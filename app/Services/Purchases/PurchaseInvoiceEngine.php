<?php

namespace App\Services\Purchases;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Inventory\InventoryEngine;
use App\Services\Invoicing\GlobalDiscount;
use App\Services\Sales\PaymentDeleter;
use App\Support\CashSessionGate;
use App\Support\DefaultAccounts;
use App\Support\PaymentMethodOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de Facturas de Compra:
 *  - post(): genera InventoryMovements + JournalEntry, marca posted
 *  - addPayment(): crea Payment + JournalEntry, recalcula payment_status
 *  - cancel(): devuelve inventario y crea asiento de reversa
 *
 * Patrón replicable después para SalesInvoice (Fase 3).
 */
class PurchaseInvoiceEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $numberer,
    ) {}

    /**
     * Contabiliza la factura: crea inventario por cada línea con producto + asiento contable.
     */
    public function post(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new RuntimeException('Solo se puede contabilizar una factura en estado borrador.');
        }

        $invoice->load(['retentions.tax', 'lines.product', 'lines.tax', 'supplier', 'location']);

        if ($invoice->lines->isEmpty()) {
            throw new RuntimeException('La factura no tiene líneas.');
        }

        return DB::transaction(function () use ($invoice) {
            $this->recalculateTotals($invoice);

            // 1. Inventario: solo la DIFERENCIA con lo que esta misma factura
            //    ya tenga puesto. En la primera contabilizacion esa diferencia
            //    es la cantidad entera; al corregir, es lo que cambio.
            $this->aplicarInventario($invoice);

            foreach ($invoice->lines as $line) {
                if ($line->product_id && $line->product?->track_inventory && $line->product->tracks_serials) {
                    $this->sincronizarSeriales($invoice, $line);
                }
            }

            // 2. Journal entry: DR Inventario por línea + DR IVA descontable | CR CxP
            $journalEntry = $this->createPurchaseJournalEntry($invoice);

            // 3. Marcar posted
            $invoice->update([
                'status' => PurchaseInvoice::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $journalEntry->id,
                'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Registra un pago contra una factura contabilizada.
     * Crea Payment + JournalEntry (DR CxP | CR Caja/Banco) + recalcula payment_status.
     */
    public function addPayment(PurchaseInvoice $invoice, array $data): Payment
    {
        if (! $invoice->isPosted()) {
            throw new RuntimeException('Solo se pueden registrar pagos sobre facturas contabilizadas.');
        }

        // Egreso real de caja: requiere un turno abierto para que el cierre
        // de caja del cajero refleje este pago. Mismo principio que ventas.
        $session = CashSessionGate::requireOpenSession();

        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor a 0.');
        }

        $balance = (float) $invoice->balance;
        if ($amount > $balance + 0.01) {
            throw new RuntimeException(sprintf(
                'El pago de $%s excede el saldo pendiente de $%s.',
                number_format($amount, 2),
                number_format($balance, 2),
            ));
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $session) {
            $cashAccountId = $data['account_id'];
            $payableAccountId = $invoice->supplier?->default_payable_account_id
                ?? DefaultAccounts::porCodigo('220505', (int) $invoice->company_id);

            if (! $payableAccountId) {
                throw new RuntimeException('No se encontró la cuenta por pagar (220505 o configurada en el proveedor).');
            }

            // 1. Asiento contable del pago
            $entry = $this->createPaymentJournalEntry(
                $invoice,
                $payableAccountId,
                $cashAccountId,
                $amount,
                $data['date'],
                $data['payment_method'],
                $data['reference'] ?? null,
            );

            // 2. Payment record — atado a la sesión actual del cajero
            // para que CashSessionSummary lo cuente como egreso del turno.
            $payment = Payment::create([
                'company_id' => $invoice->company_id,
                'paymentable_type' => PurchaseInvoice::class,
                'paymentable_id' => $invoice->id,
                'third_party_id' => $invoice->third_party_id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'account_id' => $cashAccountId,
                'cash_register_session_id' => $session->id,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'journal_entry_id' => $entry->id,
                'created_by_user_id' => Auth::id(),
            ]);

            // 3. Recalcular paid_amount + payment_status
            $this->recomputePaymentStatus($invoice);

            return $payment;
        });
    }

    /**
     * Recalcula totales de la factura desde sus líneas.
     */
    public function recalculateTotals(PurchaseInvoice $invoice): void
    {
        // Igual que en ventas: el descuento global baja la base de cada linea
        // antes de calcular el IVA. Ver App\Services\Invoicing\GlobalDiscount.
        $invoice->loadMissing('lines');
        $globalDiscount = app(GlobalDiscount::class)->aplicar($invoice);
        $invoice->load('lines');

        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($invoice->lines as $line) {
            $subtotal += (float) $line->subtotal;
            $discount += (float) $line->discount_amount;
            $tax += (float) $line->tax_amount;
            $total += (float) $line->total;
        }

        // Lo que se le retiene al proveedor no se le paga a el sino a la
        // DIAN, asi que sale del neto a pagar y no del total de la factura.
        $invoice->loadMissing('retentions');
        $retentions = round((float) $invoice->retentions->sum('amount'), 2);

        $invoice->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'global_discount_amount' => $globalDiscount,
            'tax_total' => $tax,
            'total' => $total,
            'retention_total' => $retentions,
            'net_payable' => round($total - $retentions, 2),
        ]);
    }

    /**
     * Recalcula paid_amount y actualiza payment_status según el saldo.
     */
    public function recomputePaymentStatus(PurchaseInvoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        // Contra el neto: con retencion, el proveedor nunca cobra el total y
        // la factura se quedaria en "parcial" para siempre.
        $total = (float) ($invoice->net_payable ?: $invoice->total);

        if ($paid <= 0.0) {
            $status = PurchaseInvoice::PAYMENT_PENDIENTE;
        } elseif ($paid + 0.01 < $total) {
            $status = PurchaseInvoice::PAYMENT_PARCIAL;
        } else {
            $status = PurchaseInvoice::PAYMENT_PAGADO;
        }

        // Vencido si está pendiente/parcial Y due_date pasó
        if (
            in_array($status, [PurchaseInvoice::PAYMENT_PENDIENTE, PurchaseInvoice::PAYMENT_PARCIAL], true)
            && $invoice->due_date
            && $invoice->due_date->isPast()
        ) {
            $status = PurchaseInvoice::PAYMENT_VENCIDO;
        }

        $invoice->update([
            'paid_amount' => $paid,
            'payment_status' => $status,
        ]);
    }

    /**
     * Genera el asiento contable de la factura de compra.
     * DR cuenta_inventario_producto por cada línea (subtotal sin IVA)
     * DR IVA descontable (240810) por la suma de impuestos
     * CR Cuenta por pagar del proveedor (220505 default) por el total
     */
    protected function createPurchaseJournalEntry(PurchaseInvoice $invoice): JournalEntry
    {
        $vatAccountId = DefaultAccounts::porCodigo('240810', (int) $invoice->company_id);

        $defaultExpenseAccountId = DefaultAccounts::porCodigo('6135', (int) $invoice->company_id);

        $payableAccountId = $invoice->supplier?->default_payable_account_id
            ?? DefaultAccounts::porCodigo('220505', (int) $invoice->company_id);

        if (! $payableAccountId) {
            throw new RuntimeException('Falta cuenta por pagar (220505 o configurar en el proveedor).');
        }

        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'AS');

        $entry = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => $invoice->date,
            'type' => 'purchase',
            'reference' => $invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Compra {$invoice->fullNumber()} — {$invoice->supplier->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $invoice->total,
            'total_credit' => $invoice->total,
        ]);

        $line = 1;

        // DR por cada línea con producto: usa la cuenta de inventario del producto
        foreach ($invoice->lines as $invLine) {
            $accountId = $invLine->account_id
                ?? $invLine->product?->effectiveInventoryAccountId()
                ?? $defaultExpenseAccountId;

            if (! $accountId) {
                throw new RuntimeException(
                    "Línea sin cuenta contable. Configura la cuenta de inventario del producto '{$invLine->description}' o asigna manualmente."
                );
            }

            $debitAmount = (float) $invLine->subtotal - (float) $invLine->discount_amount;

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $accountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => "Línea {$invLine->line_number}: {$invLine->description}",
                'debit' => $debitAmount,
                'credit' => 0,
            ]);
        }

        // DR IVA descontable (sumado)
        if ((float) $invoice->tax_total > 0) {
            if (! $vatAccountId) {
                throw new RuntimeException('Falta cuenta IVA descontable (240810) en el PUC.');
            }
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $vatAccountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => 'IVA descontable',
                'debit' => $invoice->tax_total,
                'credit' => 0,
            ]);
        }

        // CR por cada retencion que le practicamos al proveedor. Es un pasivo
        // con la DIAN, no con el: por eso se acredita aparte y la cuenta por
        // pagar baja en la misma medida.
        foreach ($invoice->retentions as $ret) {
            $accountId = $ret->tax?->purchase_account_id;

            if (! $accountId) {
                throw new RuntimeException(sprintf(
                    'La retención "%s" no tiene cuenta de compra configurada (Tax::purchase_account_id). '
                    .'Configúrala en Contabilidad → Impuestos.',
                    $ret->tax_name,
                ));
            }

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $accountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => "Retención {$ret->tax_name}",
                'debit' => 0,
                'credit' => $ret->amount,
            ]);
        }

        // CR Cuenta por pagar: solo lo que de verdad se le debe al proveedor.
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => $line,
            'account_id' => $payableAccountId,
            'third_party_id' => $invoice->third_party_id,
            'description' => "Cuenta por pagar {$invoice->supplier->name}",
            'debit' => 0,
            'credit' => $invoice->net_payable ?: $invoice->total,
        ]);

        return $entry;
    }

    /**
     * Genera el asiento contable de un pago.
     * DR Cuenta por pagar | CR Caja/Banco
     */
    protected function createPaymentJournalEntry(
        PurchaseInvoice $invoice,
        int $payableAccountId,
        int $cashAccountId,
        float $amount,
        string $date,
        string $method,
        ?string $reference,
    ): JournalEntry {
        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'CE'); // CE = Comprobante de Egreso

        $methodLabel = PaymentMethodOptions::nombre($method, $invoice->company_id);

        $entry = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'CE',
            'number' => $number,
            'date' => $date,
            'type' => 'payment',
            'reference' => $reference ?: $invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Pago {$methodLabel} a {$invoice->supplier->name} — Factura {$invoice->fullNumber()}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $amount,
            'total_credit' => $amount,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 1,
            'account_id' => $payableAccountId,
            'third_party_id' => $invoice->third_party_id,
            'description' => "Pago factura {$invoice->fullNumber()}",
            'debit' => $amount,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 2,
            'account_id' => $cashAccountId,
            'description' => "Salida {$methodLabel}",
            'debit' => 0,
            'credit' => $amount,
        ]);

        return $entry;
    }

    /**
     * Anula una factura contabilizada: devuelve el inventario al
     * proveedor y crea un asiento de reversa que invierte el efecto
     * contable del original. Bloqueada si la factura tiene pagos
     * registrados — los pagos deben reversarse primero.
     */
    /**
     * Lleva el inventario a lo que la factura dice AHORA, moviendo solo la
     * diferencia.
     *
     * Antes, corregir una compra deshacia todo el inventario y lo volvia a
     * aplicar. El resultado final era el mismo, pero el paso intermedio no:
     * una compra de 6 unidades con 1 ya vendida bajaba a -1 antes de volver a
     * subir, y el motor de inventario —con razon— se negaba. Asi que una venta
     * de una sola unidad bloqueaba la correccion de la factura ENTERA, aunque
     * lo que se fuera a cambiar fuera otra linea que nadie habia tocado.
     *
     * Moviendo solo la diferencia, una linea que no cambia no genera ningun
     * movimiento y por tanto no puede fallar. Solo se topa con el inventario
     * quien REBAJA una cantidad por debajo de lo que queda en existencia, que
     * es cuando el problema es de verdad.
     *
     * De paso arregla algo que tampoco estaba bien: mientras la compra estaba
     * en borrador para corregirla, su mercancia desaparecia del stock aunque
     * siguiera fisicamente en la bodega.
     */
    protected function aplicarInventario(PurchaseInvoice $invoice): void
    {
        $yaAplicado = $this->inventarioYaAplicado($invoice);

        $ahora = [];
        $lineasPorProducto = [];

        foreach ($invoice->lines as $linea) {
            if (! $linea->product_id || ! $linea->product?->track_inventory) {
                continue;
            }

            $id = (int) $linea->product_id;
            // Varias lineas del mismo producto se suman: al inventario le da
            // igual en cuantos renglones venga.
            $ahora[$id] = ($ahora[$id] ?? 0) + (float) $linea->quantity;
            $lineasPorProducto[$id][] = $linea;
        }

        $productos = array_unique(array_merge(array_keys($ahora), array_keys($yaAplicado)));

        foreach ($productos as $productoId) {
            $productoId = (int) $productoId;
            $diferencia = round(($ahora[$productoId] ?? 0) - ($yaAplicado[$productoId] ?? 0), 4);

            if (abs($diferencia) < 0.0001) {
                continue;
            }

            $lineas = $lineasPorProducto[$productoId] ?? [];
            $producto = $lineas[0]->product ?? Product::withoutGlobalScopes()->find($productoId);

            if (! $producto) {
                continue;
            }

            $esEntrada = $diferencia > 0;
            $esCorreccion = array_key_exists($productoId, $yaAplicado);

            if (! $esEntrada) {
                $this->exigirQueHayaParaRebajar($invoice, $producto, abs($diferencia));
            }

            $movimiento = $this->inventory->addMovement(
                $producto,
                $invoice->location,
                [
                    'type' => $esEntrada ? 'purchase' : 'return_to_supplier',
                    'quantity' => abs($diferencia),
                    // Costo unitario para inventario = unit_cost neto (sin IVA
                    // descontable). Si el producto ya no esta en la factura, se
                    // usa el costo con el que entro.
                    'unit_cost' => (float) ($lineas[0]->unit_cost
                        ?? $this->inventory->currentStock($productoId, (int) $invoice->location_id)),
                    // La entrada original lleva la fecha de la factura, que
                    // es cuando llego la mercancia. Una CORRECCION lleva la de
                    // hoy, porque es cuando ocurre.
                    //
                    // No es un detalle de redaccion: el saldo sale del ultimo
                    // movimiento ordenado por fecha, asi que una correccion
                    // fechada ayer queda ANTES de las ventas de hoy y el saldo
                    // la ignora. La mercancia entraba y el stock no se movia.
                    'date' => $esCorreccion ? now() : $invoice->date,
                    'reference_type' => PurchaseInvoice::class,
                    'reference_id' => $invoice->id,
                    'reference_number' => $invoice->fullNumber(),
                    'third_party_id' => $invoice->third_party_id,
                    'description' => $esCorreccion
                        ? "Corrección compra {$invoice->fullNumber()} — {$invoice->supplier->name}"
                        : "Compra {$invoice->fullNumber()} — {$invoice->supplier->name}",
                ]
            );

            foreach ($lineas as $linea) {
                $linea->update(['inventory_movement_id' => $movimiento->id]);
            }
        }
    }

    /**
     * Lo que ESTA factura ya tiene puesto en el inventario, por producto.
     *
     * Se lee de los propios movimientos y no de una columna aparte: son ellos
     * los que mandan en el saldo, asi que preguntarles es preguntarle a la
     * verdad. Una bitacora paralela se desincroniza y nadie se entera.
     *
     * `quantity` viene con signo —negativa en las salidas—, de modo que la
     * suma ya es el neto.
     *
     * @return array<int, float>
     */
    protected function inventarioYaAplicado(PurchaseInvoice $invoice): array
    {
        return InventoryMovement::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('reference_type', PurchaseInvoice::class)
            ->where('reference_id', $invoice->id)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as neto')
            ->pluck('neto', 'product_id')
            ->map(fn ($neto) => (float) $neto)
            ->all();
    }

    /** Rebajar una cantidad exige que la mercancia siga ahi. */
    protected function exigirQueHayaParaRebajar(PurchaseInvoice $invoice, Product $producto, float $cantidad): void
    {
        $disponible = $this->inventory->currentStock((int) $producto->id, (int) $invoice->location_id);

        if ($disponible + 0.0001 >= $cantidad) {
            return;
        }

        throw new RuntimeException(sprintf(
            'No se puede bajar la cantidad de «%s»: habría que sacar %s del inventario y en %s '
            .'solo quedan %s. El resto ya se vendió o se movió. Las demás líneas de esta factura '
            .'sí se pueden corregir; para ajustar la cantidad de esta usa Compras → Devoluciones.',
            $producto->name,
            $this->enCantidad($cantidad),
            $invoice->location?->name ?? 'la sede',
            $this->enCantidad($disponible),
        ));
    }

    /**
     * Pone los seriales de la linea a la par de lo capturado.
     *
     * Diferencial, por lo mismo que el inventario: los que siguen no se tocan,
     * se crean los nuevos y se quitan los que sobran. Y si uno de los que
     * sobran ya se vendio, se dice CUAL, en vez de bloquear la factura entera.
     */
    protected function sincronizarSeriales(PurchaseInvoice $invoice, PurchaseInvoiceLine $linea): void
    {
        $capturados = array_values(array_unique(array_filter(array_map(
            fn ($s) => trim((string) $s),
            is_array($linea->serials ?? []) ? $linea->serials : [],
        ))));

        $cantidad = (int) round((float) $linea->quantity);

        if (count($capturados) !== $cantidad) {
            throw new RuntimeException(sprintf(
                'La línea de "%s" tiene cantidad %d pero capturaste %d seriales. Deben coincidir.',
                $linea->product?->name ?? 'producto',
                $cantidad,
                count($capturados),
            ));
        }

        $existentes = ProductSerial::query()
            ->where('purchase_invoice_line_id', $linea->id)
            ->get();

        foreach ($existentes as $serial) {
            if (in_array($serial->serial_number, $capturados, true)) {
                continue;
            }

            if ($serial->status !== ProductSerial::STATUS_IN_STOCK) {
                throw new RuntimeException(sprintf(
                    'El serial %s de «%s» ya no está en inventario (%s), así que no se puede quitar '
                    .'de la factura. Las demás líneas sí se pueden corregir.',
                    $serial->serial_number,
                    $linea->product?->name ?? 'producto',
                    $serial->status,
                ));
            }

            // Queda en deleted_at para auditoria: desaparece del inventario,
            // no de la historia.
            $serial->delete();
        }

        $yaEstaban = $existentes->pluck('serial_number')->all();
        $nuevos = array_values(array_diff($capturados, $yaEstaban));

        if ($nuevos === []) {
            return;
        }

        // Choque con seriales de otra compra o de otro producto. El unique
        // (company_id, serial_number) lo atrapa al insert, pero el mensaje de
        // la base no le dice nada a nadie.
        $repetidos = ProductSerial::query()
            ->where('company_id', $invoice->company_id)
            ->whereIn('serial_number', $nuevos)
            ->pluck('serial_number')
            ->all();

        if ($repetidos !== []) {
            throw new RuntimeException(
                'Los siguientes seriales ya existen en tu inventario y no pueden duplicarse: '
                .implode(', ', $repetidos)
            );
        }

        foreach ($nuevos as $serial) {
            ProductSerial::create([
                'company_id' => $invoice->company_id,
                'product_id' => $linea->product_id,
                'location_id' => $invoice->location_id,
                'serial_number' => $serial,
                'status' => ProductSerial::STATUS_IN_STOCK,
                'purchase_invoice_line_id' => $linea->id,
                'received_at' => $invoice->date ?? now(),
            ]);
        }
    }

    public function cancel(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if (! $invoice->isPosted()) {
            throw new RuntimeException('Solo puedes anular una factura contabilizada.');
        }
        if ($invoice->isCancelled()) {
            throw new RuntimeException('Esta factura ya está anulada.');
        }
        if ((float) $invoice->paid_amount > 0) {
            throw new RuntimeException(
                'No puedes anular una factura con pagos registrados. Reversa primero los pagos.'
            );
        }

        $invoice->load(['retentions.tax', 'lines.product', 'supplier', 'location', 'journalEntry.lines']);

        // Bloqueo previo: si algún serial entrado por esta compra ya se vendió,
        // no podemos anular sin dejar inconsistente la venta. El usuario debe
        // anular las ventas primero (que restablecerán los seriales).
        $soldSerials = ProductSerial::query()
            ->whereIn('purchase_invoice_line_id', $invoice->lines->pluck('id'))
            ->where('status', ProductSerial::STATUS_SOLD)
            ->exists();
        if ($soldSerials) {
            throw new RuntimeException(
                'No puedes anular: hay seriales entrados por esta compra que ya se vendieron. '
                .'Anula primero esas ventas para liberar los seriales.'
            );
        }

        // Anular tambien devuelve el inventario, asi que choca con lo mismo
        // que corregir cuando la mercancia ya salio. El mensaje y la regla son
        // los mismos: escribirlos dos veces es como se separan.
        if ($sinStock = $this->porQueNoSePuedeDevolverElInventario($invoice)) {
            throw new RuntimeException($sinStock);
        }

        return DB::transaction(function () use ($invoice) {
            $this->revertirContabilizacion($invoice);

            $invoice->update([
                'status' => PurchaseInvoice::STATUS_CANCELLED,
                'payment_status' => PurchaseInvoice::PAYMENT_CANCELADA,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Deshace lo que hizo contabilizar: inventario, seriales y asiento.
     *
     * Lo usan la anulacion y la correccion. Estaba escrito dentro de
     * `cancel()`, y dejarlo ahi habria significado copiarlo para poder
     * corregir: dos reversas que empiezan iguales y se separan a la primera
     * correccion que alguien haga en una sola. Es el mismo movimiento que ya
     * se hizo en `SaleInvoiceEngine`.
     */
    protected function revertirContabilizacion(PurchaseInvoice $invoice): void
    {
        $this->revertirInventario($invoice);

        // 2. Asiento de reversa contable.
        $this->createReversalJournalEntry($invoice);
    }

    /**
     * Saca del stock todo lo que esta compra metio.
     *
     * Solo la ANULACION hace esto. Corregir ya no: ahi el inventario se lleva
     * a la cifra nueva moviendo la diferencia (ver `aplicarInventario`), que
     * es lo que permite corregir una factura aunque parte de su mercancia ya
     * se haya vendido. Anular es otra cosa: la compra entera deja de existir y
     * la mercancia tiene que salir completa, asi que aqui el bloqueo por
     * existencias si es de toda la factura.
     */
    protected function revertirInventario(PurchaseInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if (! $line->product_id || ! $line->product?->track_inventory) {
                continue;
            }
            $this->inventory->addMovement(
                $line->product,
                $invoice->location,
                [
                    'type' => 'return_to_supplier',
                    'quantity' => (float) $line->quantity,
                    'unit_cost' => (float) $line->unit_cost,
                    'date' => now(),
                    'reference_type' => PurchaseInvoice::class,
                    'reference_id' => $invoice->id,
                    'reference_number' => 'ANUL-'.$invoice->fullNumber(),
                    'third_party_id' => $invoice->third_party_id,
                    'description' => "Anulación compra {$invoice->fullNumber()}",
                ]
            );

            // Soft-delete de los seriales que entraron por esta línea
            // (todos están in_stock — los vendidos los bloquea el guard
            // de arriba). Quedan en deleted_at para auditoría.
            if ($line->product->tracks_serials) {
                ProductSerial::query()
                    ->where('purchase_invoice_line_id', $line->id)
                    ->where('status', ProductSerial::STATUS_IN_STOCK)
                    ->delete();
            }
        }
    }

    /**
     * Devuelve una factura de compra contabilizada a borrador para corregirla.
     *
     * POR QUE EXISTE. Hasta ahora, una compra contabilizada con un error
     * —una cantidad mal digitada, un IVA equivocado— solo tenia dos salidas:
     * anularla y rehacerla, o dejarla mal. Y anular exige que no haya pagos,
     * cosa que una compra de contado siempre tiene. Asi que en la practica se
     * quedaba mal.
     *
     * QUE DESHACE. El asiento contable y LOS PAGOS. Los pagos se borran a
     * proposito: si el total cambia y el pago se queda como estaba, el arqueo
     * del turno sigue mostrando la cifra vieja. Volver a registrarlo en el
     * mismo turno es lo que hace que el cierre refleje la correccion.
     *
     * QUE NO DESHACE: el inventario. La mercancia sigue en la bodega mientras
     * se corrige, y al volver a contabilizar solo se mueve la diferencia. Asi
     * una linea que no cambia no genera ningun movimiento —y por tanto no
     * puede fallar porque parte de la compra ya se haya vendido—.
     *
     * CUANDO NO SE PUEDE, y por que:
     *
     *  - Si es un DOCUMENTO SOPORTE ya aceptado por la DIAN. Ese documento
     *    existe fuera de aqui; corregirlo por dentro deja los dos sistemas
     *    hablando de cosas distintas.
     *  - Si algun pago esta en un turno de caja YA CERRADO. Ese arqueo lo
     *    firmo alguien, y cambiarlo por detras lo convierte en un documento
     *    que no significa nada.
     */
    public function prepararParaEditar(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $impedimento = $this->porQueNoSePuedeEditar($invoice);

        if ($impedimento !== null) {
            throw new RuntimeException($impedimento);
        }

        $invoice->load(['retentions.tax', 'lines.product', 'supplier', 'location', 'journalEntry.lines', 'payments']);

        return DB::transaction(function () use ($invoice) {
            // El inventario NO se deshace: la mercancia sigue en la bodega
            // mientras se corrige, y al volver a contabilizar solo se mueve la
            // diferencia. Lo unico que se reversa aqui es la contabilidad.
            $this->createReversalJournalEntry($invoice);
            $this->revertirPagos($invoice);

            $invoice->update([
                'status' => PurchaseInvoice::STATUS_DRAFT,
                'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
                'paid_amount' => 0,
                'posted_at' => null,
                'posted_by_user_id' => null,
                'journal_entry_id' => null,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Lo que impide devolver el inventario de esta compra, o null si se puede.
     *
     * Corregir y anular empiezan por lo mismo: sacar del stock lo que esta
     * compra metio. Si entre tanto esas unidades se vendieron o se movieron a
     * otra sede, ya no estan para sacarlas, y el motor de inventario se niega
     * —con razon— a dejar las existencias en negativo.
     *
     * Hasta ahora eso reventaba DESPUES de pulsar el boton, con el mensaje
     * crudo del motor de inventario y sin decir que hacer. Preguntarlo antes
     * permite apagar el boton y explicar la salida que si existe.
     *
     * Ojo con las lineas repetidas: dos lineas del mismo producto se suman,
     * porque la reversa saca las dos. Comprobandolas por separado, una compra
     * de 3+3 con 4 en existencia pasaria el filtro dos veces y fallaria igual.
     */
    public function porQueNoSePuedeDevolverElInventario(PurchaseInvoice $invoice): ?string
    {
        $invoice->loadMissing(['lines.product', 'location']);

        $deInventario = $invoice->lines
            ->filter(fn ($linea) => $linea->product_id && $linea->product?->track_inventory);

        if ($deInventario->isEmpty()) {
            return null;
        }

        $sede = (int) $invoice->location_id;

        $existencias = $this->inventory->stockForProducts(
            $deInventario->pluck('product_id')->unique()->map(fn ($id) => (int) $id)->values()->all(),
            $sede,
        );

        $aDevolver = [];

        foreach ($deInventario as $linea) {
            $id = (int) $linea->product_id;
            $aDevolver[$id] = ($aDevolver[$id] ?? 0) + (float) $linea->quantity;
        }

        foreach ($aDevolver as $productoId => $cantidad) {
            $disponible = (float) ($existencias[$productoId] ?? 0);

            if ($disponible + 0.0001 >= $cantidad) {
                continue;
            }

            $producto = $deInventario->firstWhere('product_id', $productoId)?->product;

            return sprintf(
                'Ya salieron unidades que entraron por esta compra. De «%s» entraron %s y en %s '
                .'quedan %s, así que devolver el inventario dejaría las existencias en negativo: '
                .'el sistema diría que nunca recibiste algo que sí vendiste. Para corregir la '
                .'cantidad usa Compras → Devoluciones, que ajusta el saldo con el proveedor sin '
                .'inventar unidades.',
                $producto?->name ?? 'un producto',
                $this->enCantidad($cantidad),
                $invoice->location?->name ?? 'la sede',
                $this->enCantidad($disponible),
            );
        }

        return null;
    }

    /** Cantidades sin decimales de relleno: «6», no «6.0000». */
    protected function enCantidad(float $cantidad): string
    {
        return rtrim(rtrim(number_format($cantidad, 2, ',', '.'), '0'), ',');
    }

    /**
     * El motivo por el que esta compra no se puede corregir, o null si se puede.
     *
     * Devuelve el texto en vez de un booleano para que la pantalla muestre
     * exactamente la misma razon que aplica el motor: un boton que se oculta
     * «porque si» obliga a adivinar, y dos listas de condiciones se separan.
     */
    public function porQueNoSePuedeEditar(PurchaseInvoice $invoice): ?string
    {
        if ($invoice->status === PurchaseInvoice::STATUS_DRAFT) {
            return null;
        }

        if (! $invoice->isPosted()) {
            return 'Solo se puede corregir una factura en borrador o contabilizada.';
        }

        if ($invoice->isSupportDocument()
            && ($invoice->dian_status === 'accepted' || ! empty($invoice->cufe))) {
            return 'Este documento soporte ya fue aceptado por la DIAN: existe fuera de aqui y no se '
                .'puede cambiar. Lo que corresponde es una nota de ajuste.';
        }

        // Aqui NO se pregunta por el inventario ni por los seriales.
        //
        // Antes si: si algo de esta compra ya se habia vendido, se bloqueaba
        // la factura ENTERA. Pero corregir ya no deshace el inventario, lo
        // lleva a la cifra nueva moviendo la diferencia, asi que una linea que
        // no se toca no mueve nada. Lo unico que puede chocar es rebajar una
        // cantidad por debajo de lo que queda, y eso se sabe cuando se sabe
        // QUE cambio: al volver a contabilizar, y por linea.
        //
        // Bloquear antes obligaba a adivinar lo que el usuario iba a cambiar,
        // y adivinaba mal: le impedia corregir el costo de un producto porque
        // otro distinto se habia vendido.

        $turnoCerrado = $invoice->payments()
            ->whereNotNull('cash_register_session_id')
            ->whereHas('cashSession', fn ($q) => $q->where('status', '!=', CashRegisterSession::STATUS_OPEN))
            ->exists();

        if ($turnoCerrado) {
            return 'Los pagos de esta compra estan en un turno de caja ya cerrado. Ese arqueo lo firmo '
                .'alguien, y cambiarlo por detras lo volveria un documento que no significa nada.';
        }

        return null;
    }

    /**
     * Borra los pagos de la compra reversando sus asientos.
     *
     * No se conservan: con el total cambiado, un pago viejo deja el arqueo
     * mostrando una cifra que ya no corresponde a nada.
     */
    protected function revertirPagos(PurchaseInvoice $invoice): void
    {
        foreach ($invoice->payments as $payment) {
            app(PaymentDeleter::class)->delete($payment, 'Reversado al corregir la compra');
        }
    }

    protected function createReversalJournalEntry(PurchaseInvoice $invoice): JournalEntry
    {
        $original = $invoice->journalEntry;
        if (! $original) {
            throw new RuntimeException('La factura no tiene asiento contable que reversar.');
        }

        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'AS');

        $reversal = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => now()->toDateString(),
            'type' => 'reversal',
            'reference' => 'ANUL-'.$invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Anulación compra {$invoice->fullNumber()} — {$invoice->supplier->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $invoice->total,
            'total_credit' => $invoice->total,
        ]);

        $line = 1;
        foreach ($original->lines as $origLine) {
            JournalEntryLine::create([
                'journal_entry_id' => $reversal->id,
                'line_number' => $line++,
                'account_id' => $origLine->account_id,
                'third_party_id' => $origLine->third_party_id,
                'description' => "Reversa: {$origLine->description}",
                'debit' => $origLine->credit,
                'credit' => $origLine->debit,
            ]);
        }

        return $reversal;
    }
}
