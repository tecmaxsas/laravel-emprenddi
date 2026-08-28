<?php

namespace App\Services\OrderTaking;

use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\DeliveryItem;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\OrderTaking\OrderRetention;
use App\Models\OrderTaking\Payment;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Services\Sales\DocumentNumberer;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor unico del modulo Toma pedidos. Centraliza:
 *   - reserva de consecutivo interno (PED-XXXXXX)
 *   - recomputo de totales de la cabecera desde items
 *   - registro de despachos y actualizacion de delivery_status
 *   - registro de pagos y actualizacion de payment_status/balance
 *   - conversion a factura de venta cuando ya se despacho todo
 *
 * El pedido en si no mueve inventario ni contabilidad: es un documento
 * operativo. Eso solo pasa al convertirlo en factura, y por eso la conversion
 * exige que la mercancia ya haya salido completa.
 */
class OrderEngine
{
    /**
     * Reserva el siguiente numero interno para (company_id, prefix). Auto-sanea
     * si por alguna razon existen numeros ya usados que superan el last known.
     * Mismo patron defensivo que DocumentNumberer.
     */
    public function reserveNumber(int $companyId, string $prefix = 'PED'): int
    {
        return DB::transaction(function () use ($companyId, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [
                    crc32('order_taking:'.$companyId.':'.$prefix),
                ]);
            }

            $max = Order::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $max) + 1;
        });
    }

    /**
     * Recalcula subtotal/tax_total/total del pedido desde los items, y el neto
     * a pagar descontando las retenciones.
     *
     * La retencion no es un saldo por cobrar: es un anticipo de impuesto que el
     * cliente le consigna a la DIAN en nuestro nombre. Por eso el saldo se mide
     * contra net_payable y no contra total.
     */
    public function recomputeTotals(Order $order): Order
    {
        // Igual que en refreshDeliveryStatus: recargar, no confiar en lo que ya
        // estuviera cargado, porque las lineas o las retenciones pudieron
        // cambiar en esta misma peticion.
        $order->load('items', 'retentions');
        $subtotal = (float) $order->items->sum('subtotal');
        $taxTotal = (float) $order->items->sum('tax_amount');
        $total = (float) $order->items->sum('total');
        $retentionTotal = (float) $order->retentions->sum('amount');
        $netPayable = max(0, round($total - $retentionTotal, 2));

        $order->update([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'retention_total' => round($retentionTotal, 2),
            'total' => round($total, 2),
            'net_payable' => $netPayable,
            'balance' => round($netPayable - (float) $order->paid_amount, 2),
        ]);

        return $order->refresh();
    }

    /**
     * Retenciones que le corresponden a un cliente sobre una base gravable.
     *
     * Devuelve el snapshot listo para pintar en pantalla y para guardar: si
     * manana cambian la tarifa del impuesto, el pedido ya tomado no se mueve.
     *
     * @return list<array{tax_id:int,tax_code:string,tax_name:string,tax_type:string,base_amount:float,rate:float,amount:float}>
     */
    public function suggestRetentionsFor(?ThirdParty $customer, float $base): array
    {
        if (! $customer) {
            return [];
        }

        $base = max(0, round($base, 2));

        return $customer->retentionTaxes()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (Tax $tax) => [
                'tax_id' => (int) $tax->id,
                'tax_code' => (string) $tax->code,
                'tax_name' => (string) $tax->name,
                'tax_type' => (string) $tax->type,
                'base_amount' => $base,
                'rate' => (float) $tax->rate,
                'amount' => round($base * ((float) $tax->rate / 100), 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Reemplaza las retenciones del pedido por las recibidas. No recomputa
     * totales: eso lo hace recomputeTotals(), que es quien manda en la cabecera.
     */
    public function syncRetentions(Order $order, array $rows): void
    {
        DB::transaction(function () use ($order, $rows) {
            OrderRetention::query()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->id)
                ->delete();

            foreach ($rows as $row) {
                $amount = round((float) ($row['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                OrderRetention::create([
                    'company_id' => $order->company_id,
                    'order_id' => $order->id,
                    'tax_id' => (int) $row['tax_id'],
                    'tax_code' => (string) ($row['tax_code'] ?? ''),
                    'tax_name' => (string) ($row['tax_name'] ?? ''),
                    'tax_type' => (string) ($row['tax_type'] ?? ''),
                    'base_amount' => round((float) ($row['base_amount'] ?? 0), 2),
                    'rate' => (float) ($row['rate'] ?? 0),
                    'amount' => $amount,
                ]);
            }
        });
    }

    /**
     * Registra un despacho parcial o total. Actualiza quantity_delivered en
     * cada order_item y recomputa delivery_status del pedido.
     *
     * @param array $items array de ['order_item_id' => int, 'quantity' => float]
     */
    public function registerDelivery(Order $order, array $items, ?string $deliveryNumber = null, ?string $notes = null, ?string $deliveryDate = null): Delivery
    {
        if (empty($items)) {
            throw new RuntimeException('Selecciona al menos una linea a despachar.');
        }

        return DB::transaction(function () use ($order, $items, $deliveryNumber, $notes, $deliveryDate) {
            $delivery = Delivery::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'delivery_number' => $deliveryNumber,
                'delivery_date' => $deliveryDate ?? now()->toDateString(),
                'delivered_by_user_id' => Auth::id(),
                'notes' => $notes,
            ]);

            foreach ($items as $row) {
                $qty = (float) ($row['quantity'] ?? 0);
                if ($qty <= 0) continue;

                $orderItem = OrderItem::query()
                    ->where('company_id', $order->company_id)
                    ->where('order_id', $order->id)
                    ->find($row['order_item_id']);
                if (! $orderItem) {
                    throw new RuntimeException('Linea del pedido no encontrada.');
                }

                $pending = $orderItem->pendingQuantity();
                if ($qty > $pending + 0.0001) {
                    throw new RuntimeException(
                        "No puedes despachar {$qty} de {$orderItem->description} — pendiente: {$pending}."
                    );
                }

                DeliveryItem::create([
                    'company_id' => $order->company_id,
                    'delivery_id' => $delivery->id,
                    'order_item_id' => $orderItem->id,
                    'quantity_delivered' => $qty,
                ]);

                $orderItem->update([
                    'quantity_delivered' => (float) $orderItem->quantity_delivered + $qty,
                ]);
            }

            $this->refreshDeliveryStatus($order);

            return $delivery->fresh(['items']);
        });
    }

    /**
     * Registra un abono contra un despacho y actualiza paid_amount +
     * payment_status del pedido.
     *
     * El abono cuelga del despacho, no del pedido: asi se sabe que entrega esta
     * pagando el cliente. El tope sigue siendo el saldo del pedido y no el
     * valor del despacho, porque es normal que un solo pago cubra mas de una
     * entrega o que abonen de mas sobre una.
     */
    public function registerPayment(Delivery $delivery, array $payload): Payment
    {
        $order = $delivery->order;

        if (! $order) {
            throw new RuntimeException('El despacho no tiene pedido asociado.');
        }

        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('El monto del abono debe ser mayor a 0.');
        }
        if ($amount > (float) $order->balance + 0.01) {
            throw new RuntimeException(
                "Monto excede el saldo pendiente (\${$order->balance})."
            );
        }

        return DB::transaction(function () use ($order, $delivery, $payload, $amount) {
            $payment = Payment::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'delivery_id' => $delivery->id,
                'payment_date' => $payload['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'account_id' => $payload['account_id'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->refreshPaymentStatus($order);

            return $payment;
        });
    }

    /**
     * Convierte un pedido despachado por completo en factura de venta.
     *
     * El pedido es un documento operativo: no mueve inventario ni genera
     * asientos. La factura es donde todo eso aterriza, asi que aqui se
     * descarga el inventario y se contabiliza la venta — por eso solo se
     * permite cuando ya salio TODO de la bodega. Facturar un pedido a medio
     * despachar descargaria mercancia que todavia esta en el estante.
     *
     * Los abonos que se registraron contra los despachos se replican como
     * pagos de la factura: es la primera vez que ese dinero toca la
     * contabilidad, asi que no hay doble conteo.
     *
     * @param  string  $invoiceKind  pos | electronic
     * @param  bool  $allowNegativeStock  facturar aunque no haya saldo en bodega
     */
    public function convertToInvoice(Order $order, string $invoiceKind = 'pos', bool $allowNegativeStock = false): SaleInvoice
    {
        if ($order->isInvoiced()) {
            throw new RuntimeException(
                'Este pedido ya se facturó con la '.$order->saleInvoice?->fullNumber().'.'
            );
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            throw new RuntimeException('El pedido está anulado.');
        }

        $order->load('items', 'retentions');

        if (! $order->isFullyDelivered()) {
            throw new RuntimeException(
                'Solo se factura lo que ya salió completo. Despacha lo que falta y vuelve a intentarlo.'
            );
        }

        if ($order->items->isEmpty()) {
            throw new RuntimeException('El pedido no tiene líneas.');
        }

        if (! $order->location_id) {
            throw new RuntimeException(
                'El pedido no tiene sede asignada y la numeración de la factura sale de la resolución de la sede.'
            );
        }

        return DB::transaction(function () use ($order, $invoiceKind, $allowNegativeStock) {
            $doc = app(DocumentNumberer::class)->reserveForLocation($order->location_id, $invoiceKind);

            $invoice = SaleInvoice::create([
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'third_party_id' => $order->third_party_id,
                'prefix' => $doc['prefix'],
                'number' => $doc['number'],
                'invoice_kind' => $doc['kind'],
                'dian_resolution_id' => $doc['resolution_id'],
                'date' => now()->toDateString(),
                'currency' => 'COP',
                'status' => 'draft',
                'payment_status' => 'pendiente',
                'created_by_user_id' => Auth::id(),
                'seller_user_id' => $order->seller_user_id ?? Auth::id(),
                'description' => 'Pedido '.$order->fullNumber(),
                'notes' => $order->notes,
            ]);

            $lineNumber = 1;
            foreach ($order->items as $item) {
                // Se factura lo entregado, no lo pedido. A 100% son el mismo
                // numero, pero dejarlo explicito evita facturar de mas si
                // manana se admite facturar despachos parciales.
                $cantidad = (float) $item->quantity_delivered;

                if ($cantidad <= 0) {
                    continue;
                }

                $invoice->lines()->create([
                    'line_number' => $lineNumber++,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $cantidad,
                    'unit_price' => (float) $item->unit_price_before_tax,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'tax_rate' => (float) $item->tax_rate,
                    'tax_amount' => (float) $item->tax_amount,
                    'subtotal' => (float) $item->subtotal,
                    'total' => (float) $item->total,
                ]);
            }

            // Antes de postear: recalculateTotals las necesita para el
            // net_payable, que es contra lo que se mide el saldo.
            foreach ($order->retentions as $ret) {
                $invoice->retentions()->create([
                    'tax_id' => $ret->tax_id,
                    'tax_code' => $ret->tax_code,
                    'tax_name' => $ret->tax_name,
                    'tax_type' => $ret->tax_type,
                    'base_amount' => $ret->base_amount,
                    'rate' => $ret->rate,
                    'amount' => $ret->amount,
                ]);
            }

            $invoiceEngine = app(SaleInvoiceEngine::class);
            $invoice = $invoiceEngine->post($invoice->fresh(['lines', 'retentions']), $allowNegativeStock);

            $this->replayPayments($order, $invoice, $invoiceEngine);

            $order->update(['sale_invoice_id' => $invoice->id]);

            return $invoice->fresh(['lines', 'retentions', 'payments']);
        });
    }

    /**
     * Lleva los abonos del pedido a la factura recien creada.
     *
     * Si por redondeos el ultimo abono no cabe en el saldo, se recorta en vez
     * de tumbar la conversion entera: la factura ya esta contabilizada y
     * dejarla a medias seria peor que un centavo de diferencia.
     */
    protected function replayPayments(Order $order, SaleInvoice $invoice, SaleInvoiceEngine $invoiceEngine): void
    {
        foreach ($order->payments()->orderBy('payment_date')->orderBy('id')->get() as $abono) {
            $saldo = (float) $invoice->fresh()->balance;

            if ($saldo <= 0.01) {
                break;
            }

            $monto = min((float) $abono->amount, $saldo);

            $invoiceEngine->addPayment($invoice, [
                'amount' => $monto,
                'payment_method' => $abono->payment_method ?? 'cash',
                'account_id' => $abono->account_id
                    ?: PaymentAccountResolver::forMethod($abono->payment_method, $order->company_id),
                'date' => $abono->payment_date?->toDateString() ?? now()->toDateString(),
                'reference' => $abono->reference,
                'description' => 'Abono del pedido '.$order->fullNumber()
                    .($abono->delivery ? ' — '.$abono->delivery->label() : ''),
            ]);
        }
    }

    /**
     * Recalcula delivery_status del pedido:
     *   pending  = ningun item entregado
     *   partial  = algun item con delivered > 0 pero total < ordered
     *   delivered = todos los items delivered >= ordered
     */
    public function refreshDeliveryStatus(Order $order): void
    {
        // load() y no loadMissing(): se llama justo despues de mover las
        // cantidades, y si las lineas ya venian cargadas loadMissing devuelve
        // la coleccion vieja y el estado se calcula con datos de antes del
        // despacho.
        $order->load('items');
        $totalOrdered = (float) $order->items->sum('quantity_ordered');
        $totalDelivered = (float) $order->items->sum('quantity_delivered');

        $deliveryStatus = match (true) {
            $totalOrdered <= 0 => 'pending',
            $totalDelivered <= 0 => 'pending',
            $totalDelivered + 0.0001 >= $totalOrdered => 'delivered',
            default => 'partial',
        };

        $newStatus = $order->status;
        if ($deliveryStatus === 'delivered' && $newStatus !== Order::STATUS_CANCELLED) {
            $newStatus = Order::STATUS_FULLY_DELIVERED;
        } elseif ($deliveryStatus === 'partial' && $newStatus !== Order::STATUS_CANCELLED) {
            $newStatus = Order::STATUS_PARTIAL_DELIVERED;
        }

        $order->update([
            'delivery_status' => $deliveryStatus,
            'status' => $newStatus,
        ]);
    }

    /**
     * Recalcula payment_status/balance desde la suma de payments.
     *
     * Se mide contra net_payable (total menos retenciones), que es lo que el
     * cliente realmente debe poner de su bolsillo.
     */
    public function refreshPaymentStatus(Order $order): void
    {
        $paid = (float) Payment::query()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->id)
            ->sum('amount');

        $payable = (float) $order->net_payable;
        $balance = round($payable - $paid, 2);
        $status = match (true) {
            $paid <= 0 => 'pendiente',
            $paid + 0.01 < $payable => 'parcial',
            default => 'pagado',
        };

        $order->update([
            'paid_amount' => round($paid, 2),
            'balance' => max(0, $balance),
            'payment_status' => $status,
        ]);
    }
}
