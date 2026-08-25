<?php

namespace App\Services\Parking;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Parking\ParkingMembership;
use App\Models\Parking\ParkingSession;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Services\Dian\PosDianTransmitter;
use App\Models\ThirdParty;
use App\Services\Sales\DocumentNumberer;
use App\Services\Sales\SaleInvoiceEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera la factura (POS o electronica DIAN) por una sesion de parqueo
 * cerrada con cobro > 0, registra el pago y deja la sesion conectada a la
 * SaleInvoice creada.
 *
 * Sesiones con mensualidad/convenio (amount=0) NO pasan por aqui; se
 * cierran sin factura porque el cobro va separado.
 *
 * Reusa toda la infraestructura existente: DocumentNumberer (reserva de
 * consecutivo + resolucion), SaleInvoiceEngine (posting contable, calculo
 * de impuestos, recalculacion de totales, addPayment).
 */
class ParkingBillingEngine
{
    public function __construct(
        protected SaleInvoiceEngine $sales,
        protected DocumentNumberer $numberer,
        protected ParkingProductProvisioner $products,
    ) {}

    /**
     * @param  array  $payload  ['invoice_kind', 'payment_method', 'account_id',
     *                           'paid_amount', 'third_party_id'?, 'reference'?,
     *                           'extras'?]
     *
     *  Cada extra es un array:
     *    ['product_id', 'quantity', 'unit_price', 'tax_id'?, 'tax_rate'?]
     *
     *  unit_price se interpreta como precio AL PUBLICO (incluye IVA si
     *  el producto tiene tax). Se descompone para guardar base + impuesto.
     */
    public function issueForSession(ParkingSession $session, array $payload): SaleInvoice
    {
        $extras = $payload['extras'] ?? [];

        $parkingCovered = (bool) $session->parking_membership_id;
        $parkingAmount = (float) $session->amount;
        $hasParkingCharge = ! $parkingCovered && $parkingAmount > 0;
        $hasExtras = ! empty($extras);

        if (! $hasParkingCharge && ! $hasExtras) {
            throw new RuntimeException(
                $parkingCovered
                    ? 'La sesión está cubierta por mensualidad/convenio y no hay productos adicionales que facturar.'
                    : 'La sesión tiene monto 0 y no hay productos adicionales; no requiere factura.'
            );
        }
        if ($session->status !== ParkingSession::STATUS_CLOSED
            && $session->status !== ParkingSession::STATUS_LOST_TICKET) {
            throw new RuntimeException('Solo se factura una sesión cerrada o ticket perdido.');
        }
        if ($session->sale_invoice_id) {
            throw new RuntimeException('Esta sesión ya tiene factura emitida.');
        }

        $invoiceKind = in_array($payload['invoice_kind'] ?? 'pos', ['pos', 'electronic'], true)
            ? $payload['invoice_kind']
            : 'pos';
        $paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
        $accountId = (int) ($payload['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new RuntimeException('Falta seleccionar la cuenta contable del cobro.');
        }
        $paidAmount = (float) ($payload['paid_amount'] ?? $session->amount);

        $company = Company::find($session->company_id);
        if (! $company) {
            throw new RuntimeException('Sin empresa asociada.');
        }
        $location = $this->resolveLocation($session);
        if (! $location) {
            throw new RuntimeException(
                'El parqueadero no tiene sede asignada para facturar. '
                .'Configura "Sede de facturación" en la edición del parqueadero.'
            );
        }
        $customer = $this->resolveCustomer($company, $payload['third_party_id'] ?? null);
        $product = $this->products->ensure($company);

        // Turno de caja del cajero — obligatorio. Si no hay turno abierto, no
        // se puede facturar (las ventas tienen que quedar en el cierre de caja).
        $cashSession = $this->resolveOpenCashSession();
        if (! $cashSession) {
            throw new RuntimeException(
                'No tienes un turno de caja abierto. Abre tu caja en POS → Apertura de caja '
                .'antes de cobrar el parqueadero.'
            );
        }

        $invoice = DB::transaction(function () use (
            $session, $invoiceKind, $location, $customer, $product, $company,
            $accountId, $paymentMethod, $paidAmount, $payload, $cashSession,
            $extras, $hasParkingCharge,
        ) {
            // 1. Reservar consecutivo (resolucion POS o DIAN segun kind)
            $doc = $this->numberer->reserveForLocation((int) $location->id, $invoiceKind);

            // 2. Crear factura cabecera
            $invoice = SaleInvoice::create([
                'company_id' => $company->id,
                'location_id' => $location->id,
                'third_party_id' => $customer->id,
                'cash_register_session_id' => $cashSession->id,
                'prefix' => $doc['prefix'],
                'number' => $doc['number'],
                'invoice_kind' => $doc['kind'],
                'dian_resolution_id' => $doc['resolution_id'],
                'date' => now()->toDateString(),
                'currency' => 'COP',
                'status' => 'draft',
                'payment_status' => 'pendiente',
                'created_by_user_id' => Auth::id(),
                'seller_user_id' => Auth::id(),
                'description' => "Parqueo placa {$session->plate}".(! empty($extras) ? ' + servicios' : ''),
            ]);

            $lineNumber = 1;

            // 3. Linea de parqueo (solo si hay cargo no cubierto por mensualidad)
            if ($hasParkingCharge) {
                $this->createInvoiceLine(
                    invoice: $invoice,
                    lineNumber: $lineNumber++,
                    productId: $product->id,
                    description: sprintf(
                        'Parqueo placa %s — %d min — %s a %s',
                        $session->plate,
                        (int) ($session->total_minutes ?? 0),
                        $session->entry_at?->format('d/m/Y H:i') ?? '—',
                        $session->exit_at?->format('d/m/Y H:i') ?? '—',
                    ),
                    quantity: 1,
                    totalAtPublic: (float) $session->amount,
                    taxId: $product->default_sale_tax_id,
                );
            }

            // 4. Lineas adicionales (productos / servicios extras)
            foreach ($extras as $extra) {
                $qty = max(1, (int) ($extra['quantity'] ?? 1));
                $unitPriceAtPublic = (float) ($extra['unit_price'] ?? 0);
                $totalAtPublic = round($qty * $unitPriceAtPublic, 2);
                if ($totalAtPublic <= 0) continue;
                $this->createInvoiceLine(
                    invoice: $invoice,
                    lineNumber: $lineNumber++,
                    productId: (int) $extra['product_id'],
                    description: (string) ($extra['product_name'] ?? ''),
                    quantity: $qty,
                    totalAtPublic: $totalAtPublic,
                    taxId: $extra['tax_id'] ?? null,
                );
            }

            // 5. Postear (SaleInvoiceEngine recalcula totales y crea asientos)
            $invoice = $this->sales->post($invoice->fresh(['lines']));

            // 6. Pago
            if ($paidAmount > 0) {
                $this->sales->addPayment($invoice, [
                    'amount' => min($paidAmount, (float) $invoice->fresh()->balance),
                    'payment_method' => $paymentMethod,
                    'account_id' => $accountId,
                    'date' => now()->toDateString(),
                    'reference' => $payload['reference'] ?? "Parqueo {$session->plate}",
                    'description' => "Cobro parqueadero {$invoice->fullNumber()}",
                ]);
            }

            // 7. Cerrar el bucle en la sesion
            $session->update([
                'sale_invoice_id' => $invoice->id,
                'payment_method' => $paymentMethod,
                'paid_amount' => $paidAmount,
            ]);

            return $invoice->fresh();
        });

        // Facturacion electronica fuera de la transaccion: es una llamada HTTP
        // de varios segundos y adentro mantendria los locks abiertos. Se espera
        // la respuesta para que el tiquete salga con CUFE y QR.
        app(PosDianTransmitter::class)->transmit($invoice);

        return $invoice->fresh();
    }

    /**
     * Emite una factura por una mensualidad o convenio corporativo. El cliente
     * de la factura es el tercero asociado a la membresia (no Consumidor Final),
     * para que la informacion exogena agrupe correctamente por cliente y el
     * contador pueda hacer cartera/seguimiento.
     *
     * El monto facturado es membership->amount (precio al publico). Si el
     * producto PARKING-SVC tiene IVA, se descompone.
     *
     * Idempotencia: no bloqueo facturar dos veces — el operador decide. Para
     * tracking, en la descripcion del header queda el nombre de la mensualidad
     * y el periodo de vigencia.
     *
     * @param  array  $payload  ['invoice_kind', 'payment_method', 'account_id',
     *                           'paid_amount'?, 'period_start'?, 'period_end'?]
     */
    public function issueForMembership(ParkingMembership $membership, array $payload): SaleInvoice
    {
        $amount = (float) $membership->amount;
        if ($amount <= 0) {
            throw new RuntimeException('La mensualidad/convenio tiene monto 0; no requiere factura.');
        }

        $invoiceKind = in_array($payload['invoice_kind'] ?? 'pos', ['pos', 'electronic'], true)
            ? $payload['invoice_kind']
            : 'pos';
        $paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
        $accountId = (int) ($payload['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new RuntimeException('Falta seleccionar la cuenta contable del cobro.');
        }
        $paidAmount = (float) ($payload['paid_amount'] ?? $amount);

        $company = Company::find($membership->company_id);
        if (! $company) {
            throw new RuntimeException('Sin empresa asociada a la mensualidad.');
        }

        $lot = $membership->parkingLot()->with('defaultLocation')->first();
        $location = $lot?->defaultLocation
            ?? Location::query()
                ->where('company_id', $company->id)
                ->where('active', true)
                ->orderByDesc('is_main')
                ->orderBy('id')
                ->first();
        if (! $location) {
            throw new RuntimeException('El parqueadero no tiene sede asignada para facturar.');
        }

        $customer = $membership->customer()->first();
        if (! $customer) {
            throw new RuntimeException('La mensualidad no tiene cliente asociado.');
        }
        if ($customer->company_id !== $company->id) {
            throw new RuntimeException('Cliente y empresa no coinciden.');
        }

        $product = $this->products->ensure($company);

        $cashSession = $this->resolveOpenCashSession();
        if (! $cashSession) {
            throw new RuntimeException(
                'No tienes un turno de caja abierto. Abre tu caja antes de cobrar mensualidades.'
            );
        }

        $periodStart = $payload['period_start'] ?? $membership->start_date?->toDateString();
        $periodEnd = $payload['period_end'] ?? $membership->end_date?->toDateString();
        $platesLabel = implode(', ', array_map(fn ($p) => strtoupper((string) $p), $membership->plates ?? []));

        $invoice = DB::transaction(function () use (
            $company, $location, $customer, $product, $cashSession,
            $invoiceKind, $paymentMethod, $accountId, $paidAmount,
            $amount, $membership, $periodStart, $periodEnd, $platesLabel,
        ) {
            $doc = $this->numberer->reserveForLocation((int) $location->id, $invoiceKind);

            $invoice = SaleInvoice::create([
                'company_id' => $company->id,
                'location_id' => $location->id,
                'third_party_id' => $customer->id,
                'cash_register_session_id' => $cashSession->id,
                'prefix' => $doc['prefix'],
                'number' => $doc['number'],
                'invoice_kind' => $doc['kind'],
                'dian_resolution_id' => $doc['resolution_id'],
                'date' => now()->toDateString(),
                'currency' => 'COP',
                'status' => 'draft',
                'payment_status' => 'pendiente',
                'created_by_user_id' => Auth::id(),
                'seller_user_id' => Auth::id(),
                'description' => "Mensualidad/Convenio: {$membership->name}",
            ]);

            $description = sprintf(
                '%s — Placas: %s — Vigencia %s a %s',
                $membership->name,
                $platesLabel !== '' ? $platesLabel : '—',
                $periodStart ?? '—',
                $periodEnd ?? '—',
            );

            $this->createInvoiceLine(
                invoice: $invoice,
                lineNumber: 1,
                productId: $product->id,
                description: $description,
                quantity: 1,
                totalAtPublic: $amount,
                taxId: $product->default_sale_tax_id,
            );

            $invoice = $this->sales->post($invoice->fresh(['lines']));

            if ($paidAmount > 0) {
                $this->sales->addPayment($invoice, [
                    'amount' => min($paidAmount, (float) $invoice->fresh()->balance),
                    'payment_method' => $paymentMethod,
                    'account_id' => $accountId,
                    'date' => now()->toDateString(),
                    'reference' => "Mensualidad {$membership->name}",
                    'description' => "Cobro mensualidad {$invoice->fullNumber()}",
                ]);
            }

            return $invoice->fresh();
        });

        // Facturacion electronica fuera de la transaccion: es una llamada HTTP
        // de varios segundos y adentro mantendria los locks abiertos. Se espera
        // la respuesta para que el tiquete salga con CUFE y QR.
        app(PosDianTransmitter::class)->transmit($invoice);

        return $invoice->fresh();
    }

    /**
     * Crea una linea de SaleInvoice descomponiendo el precio al publico
     * (incluye IVA) en base + impuesto, usando la tasa del Tax referenciado.
     */
    protected function createInvoiceLine(
        SaleInvoice $invoice,
        int $lineNumber,
        int $productId,
        string $description,
        int $quantity,
        float $totalAtPublic,
        ?int $taxId,
    ): void {
        $taxRate = $taxId ? (float) (Tax::find($taxId)?->rate ?? 0) : 0;
        $subtotal = $taxRate > 0
            ? round($totalAtPublic / (1 + $taxRate / 100), 2)
            : $totalAtPublic;
        $taxAmount = round($totalAtPublic - $subtotal, 2);

        $invoice->lines()->create([
            'line_number' => $lineNumber,
            'product_id' => $productId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $quantity > 0 ? round($subtotal / $quantity, 4) : $subtotal,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'tax_id' => $taxId,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'subtotal' => $subtotal,
            'total' => $totalAtPublic,
        ]);
    }

    /**
     * Turno abierto del cajero actual. Si hay multiples (no deberia), gana
     * el mas reciente. Si el cajero abrio caja en otra sede, igual sirve —
     * el turno no esta amarrado a la sede de la factura porque un usuario
     * puede operar varias sedes.
     */
    protected function resolveOpenCashSession(): ?CashRegisterSession
    {
        if (! Auth::id()) return null;
        return CashRegisterSession::query()
            ->where('cashier_user_id', Auth::id())
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    protected function resolveLocation(ParkingSession $session): ?Location
    {
        $lot = $session->parkingLot()->with('defaultLocation')->first();
        if ($lot?->defaultLocation) {
            return $lot->defaultLocation;
        }
        // Fallback: sede principal de la empresa
        return Location::query()
            ->where('company_id', $session->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->first();
    }

    protected function resolveCustomer(Company $company, ?int $thirdPartyId): ThirdParty
    {
        if ($thirdPartyId) {
            $tp = ThirdParty::find($thirdPartyId);
            if ($tp && $tp->company_id === $company->id) {
                return $tp;
            }
        }
        // Por defecto Consumidor Final (creado por CompanyOnboarding)
        return ThirdParty::firstOrCreate(
            ['company_id' => $company->id, 'document_number' => '222222222'],
            [
                'person_type' => 'natural',
                'document_type' => 'cc',
                'name' => 'Consumidor Final',
                'is_customer' => true,
                'address' => 'Sin dirección',
                'active' => true,
            ],
        );
    }
}
