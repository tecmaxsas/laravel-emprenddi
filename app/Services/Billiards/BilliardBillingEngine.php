<?php

namespace App\Services\Billiards;

use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardSession;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Services\Dian\PosDianTransmitter;
use App\Services\Invoicing\GlobalDiscount;
use App\Services\Sales\DocumentNumberer;
use App\Services\Sales\SaleInvoiceEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emite la factura (tiquete POS o electronica DIAN) de una cuenta de mesa
 * ya cerrada, registra el pago y deja la cuenta conectada a la factura.
 *
 * No calcula nada: los renglones vienen de BilliardAccount con la base y el
 * impuesto ya descompuestos. Aqui solo se arma el documento y se delega en
 * la infraestructura que ya existe —DocumentNumberer para el consecutivo y
 * la resolucion, SaleInvoiceEngine para el posteo contable, la descarga de
 * inventario de las cervezas y el recalculo de totales—.
 */
class BilliardBillingEngine
{
    public function __construct(
        protected SaleInvoiceEngine $sales,
        protected DocumentNumberer $numberer,
        protected BilliardAccount $account,
    ) {}

    /**
     * @param  array{invoice_kind?: string, payment_method?: string, account_id?: int,
     *               paid_amount?: float|null, third_party_id?: int|null,
     *               cash_received?: float|null, reference?: string}  $payload
     */
    public function issueForSession(BilliardSession $sesion, array $payload): SaleInvoice
    {
        if ($sesion->status !== BilliardSession::STATUS_CLOSED) {
            throw new RuntimeException('Solo se factura una cuenta cerrada. Cierra la mesa primero.');
        }

        if ($sesion->sale_invoice_id) {
            throw new RuntimeException('Esta cuenta ya tiene factura emitida.');
        }

        $preparado = $this->account->paraFacturar($sesion);

        if ($preparado['lineas'] === []) {
            throw new RuntimeException(
                'La cuenta no tiene nada que cobrar: ni tiempo ni consumos. '
                .'Si fue una cortesía completa, no necesita factura.'
            );
        }

        $invoiceKind = in_array($payload['invoice_kind'] ?? 'pos', ['pos', 'electronic'], true)
            ? $payload['invoice_kind']
            : 'pos';
        $paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
        $accountId = (int) ($payload['account_id'] ?? 0);

        if ($accountId <= 0) {
            throw new RuntimeException('Falta seleccionar la cuenta contable del cobro.');
        }

        $company = Company::find($sesion->company_id);

        if (! $company) {
            throw new RuntimeException('Sin empresa asociada a la cuenta.');
        }

        $location = $this->resolverSede($sesion->hall, (int) $company->id);

        if (! $location) {
            throw new RuntimeException(
                'La sala no tiene sede asignada para facturar. '
                .'Configura "Sede de facturación" en la edición de la sala.'
            );
        }

        $customer = $this->resolverCliente($company, $payload['third_party_id'] ?? $sesion->third_party_id);

        // Turno de caja obligatorio: la venta tiene que quedar en el cierre de
        // alguien, o el arqueo nunca cuadra contra lo facturado.
        $cashSession = $this->turnoAbierto();

        if (! $cashSession) {
            throw new RuntimeException(
                'No tienes un turno de caja abierto. Abre tu caja en POS → Apertura de caja '
                .'antes de cobrar una mesa.'
            );
        }

        $paidAmount = isset($payload['paid_amount']) && $payload['paid_amount'] !== null
            ? (float) $payload['paid_amount']
            : (float) $sesion->total;

        $invoice = DB::transaction(function () use (
            $sesion, $company, $location, $customer, $cashSession,
            $invoiceKind, $paymentMethod, $accountId, $paidAmount, $payload, $preparado,
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
                'origin' => SaleInvoice::ORIGIN_BILLIARDS,
                'dian_resolution_id' => $doc['resolution_id'],
                'date' => now()->toDateString(),
                'currency' => 'COP',
                'status' => 'draft',
                'payment_status' => 'pendiente',
                // Las cortesías y rebajas de la cuenta, sobre la base gravable.
                'global_discount_type' => GlobalDiscount::TYPE_AMOUNT,
                'global_discount_value' => $preparado['descuento_base'],
                'created_by_user_id' => Auth::id(),
                'seller_user_id' => Auth::id(),
                'description' => trim(sprintf(
                    '%s%s',
                    $sesion->table?->etiqueta() ?? 'Mesa de billar',
                    $sesion->customer_label ? ' — '.$sesion->customer_label : '',
                )),
            ]);

            $numeroDeLinea = 1;

            foreach ($preparado['lineas'] as $linea) {
                $cantidad = (float) $linea['quantity'];
                $base = (float) $linea['subtotal'];

                $invoice->lines()->create([
                    'line_number' => $numeroDeLinea++,
                    'product_id' => $linea['product_id'],
                    'description' => $linea['description'],
                    'quantity' => $cantidad,
                    // Precio unitario SIN impuesto: la base entre la cantidad.
                    // La cuenta guarda el precio al público y aquí llega ya
                    // descompuesto, para que no haya dos cálculos de IVA.
                    'unit_price' => $cantidad > 0 ? round($base / $cantidad, 4) : $base,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'tax_id' => $linea['tax_id'],
                    'tax_rate' => $linea['tax_rate'],
                    'tax_amount' => $linea['tax_amount'],
                    'subtotal' => $base,
                    'total' => $linea['total'],
                ]);
            }

            // post() recalcula totales, reparte el descuento global, crea los
            // asientos y descarga el inventario de lo que se consumió.
            $invoice = $this->sales->post($invoice->fresh(['lines']));

            $saldo = (float) $invoice->fresh()->balance;
            $aCobrar = min(round($paidAmount, 2), round($saldo, 2));

            if ($aCobrar > 0) {
                $this->sales->addPayment($invoice, [
                    'amount' => $aCobrar,
                    'cash_received' => $payload['cash_received'] ?? null,
                    'payment_method' => $paymentMethod,
                    'account_id' => $accountId,
                    'date' => now()->toDateString(),
                    'reference' => $payload['reference'] ?? ($sesion->table?->etiqueta() ?? 'Billar'),
                    'description' => 'Cobro mesa '.$invoice->fullNumber(),
                ]);
            }

            $invoice = $invoice->fresh();

            $sesion->update([
                'sale_invoice_id' => $invoice->id,
                'payment_method' => $paymentMethod,
                'paid_amount' => (float) $invoice->paid_amount,
            ]);

            return $invoice;
        });

        // La electrónica va fuera de la transacción: es una llamada HTTP de
        // varios segundos y adentro mantendría los locks abiertos. Se espera
        // la respuesta para que el tiquete salga con CUFE y QR.
        app(PosDianTransmitter::class)->transmit($invoice);

        return $invoice->fresh();
    }

    /* ------------------------------------------------------------------ */

    protected function resolverSede(?BilliardHall $sala, int $companyId): ?Location
    {
        if ($sala?->default_location_id) {
            $sede = Location::query()->find($sala->default_location_id);

            if ($sede && (int) $sede->company_id === $companyId) {
                return $sede;
            }
        }

        return Location::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->first();
    }

    protected function resolverCliente(Company $company, ?int $thirdPartyId): ThirdParty
    {
        if ($thirdPartyId) {
            $tercero = ThirdParty::find($thirdPartyId);

            if ($tercero && (int) $tercero->company_id === (int) $company->id) {
                return $tercero;
            }
        }

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

    protected function turnoAbierto(): ?CashRegisterSession
    {
        if (! Auth::id()) {
            return null;
        }

        return CashRegisterSession::query()
            ->where('cashier_user_id', Auth::id())
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }
}
