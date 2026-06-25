<?php

namespace App\Services\Parking;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Parking\ParkingSession;
use App\Models\SaleInvoice;
use App\Models\Tax;
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
     *                           'paid_amount', 'third_party_id'?, 'reference'?]
     */
    public function issueForSession(ParkingSession $session, array $payload): SaleInvoice
    {
        if ($session->parking_membership_id) {
            throw new RuntimeException('La sesión está cubierta por mensualidad/convenio: no genera factura.');
        }
        if ($session->status !== ParkingSession::STATUS_CLOSED
            && $session->status !== ParkingSession::STATUS_LOST_TICKET) {
            throw new RuntimeException('Solo se factura una sesión cerrada o ticket perdido.');
        }
        if ($session->sale_invoice_id) {
            throw new RuntimeException('Esta sesión ya tiene factura emitida.');
        }
        if ((float) $session->amount <= 0) {
            throw new RuntimeException('La sesión tiene monto 0; no requiere factura.');
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

        return DB::transaction(function () use (
            $session, $invoiceKind, $location, $customer, $product, $company,
            $accountId, $paymentMethod, $paidAmount, $payload, $cashSession,
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
                'description' => "Parqueo placa {$session->plate}",
            ]);

            // 3. Linea unica: servicio de parqueadero (descripcion enriquecida)
            $taxId = $product->default_sale_tax_id;
            $taxRate = $taxId ? (float) (Tax::find($taxId)?->rate ?? 0) : 0;
            $totalAmount = (float) $session->amount;

            // El amount de la sesion ya es el cobro final. Si el producto
            // tiene IVA, hay que descomponer para guardar base + impuesto
            // consistentes con el modelo contable.
            $subtotal = $taxRate > 0 ? round($totalAmount / (1 + $taxRate / 100), 2) : $totalAmount;
            $taxAmount = round($totalAmount - $subtotal, 2);

            $description = sprintf(
                'Parqueo placa %s — %d min — %s a %s',
                $session->plate,
                (int) ($session->total_minutes ?? 0),
                $session->entry_at?->format('d/m/Y H:i') ?? '—',
                $session->exit_at?->format('d/m/Y H:i') ?? '—',
            );

            $invoice->lines()->create([
                'line_number' => 1,
                'product_id' => $product->id,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $subtotal,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'tax_id' => $taxId,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'subtotal' => $subtotal,
                'total' => $totalAmount,
            ]);

            // 4. Postear (SaleInvoiceEngine recalcula totales y crea asientos)
            $invoice = $this->sales->post($invoice->fresh(['lines']));

            // 5. Pago
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

            // 6. Cerrar el bucle en la sesion
            $session->update([
                'sale_invoice_id' => $invoice->id,
                'payment_method' => $paymentMethod,
                'paid_amount' => $paidAmount,
            ]);

            return $invoice->fresh();
        });
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
