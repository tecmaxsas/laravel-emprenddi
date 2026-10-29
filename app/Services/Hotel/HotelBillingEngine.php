<?php

namespace App\Services\Hotel;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Hotel\Stay;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Services\Dian\PosDianTransmitter;
use App\Services\Sales\CustomerAdvanceService;
use App\Services\Sales\DocumentNumberer;
use App\Services\Sales\SaleInvoiceEngine;
use App\Services\Invoicing\GlobalDiscount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Convierte el folio en factura y cierra la estadía.
 *
 * Reusa toda la infraestructura que ya existe: DocumentNumberer para el
 * consecutivo y la resolución, SaleInvoiceEngine para el posteo contable, el
 * cálculo de impuestos y el pago, PosDianTransmitter para la electrónica.
 * Nada de eso se reimplementa: una segunda forma de facturar en el sistema
 * sería una segunda forma de equivocarse con la DIAN.
 *
 * Dos decisiones que no son obvias:
 *
 *  - **Las rebajas del folio entran como descuento global**, no como líneas
 *    negativas. La DIAN rechaza una línea de factura con valor negativo, y el
 *    descuento global además baja la base gravable antes de calcular el IVA,
 *    que es lo que corresponde a un descuento no condicionado.
 *  - **La estadía se cierra dentro de la misma transacción que factura.** Si
 *    se cerrara antes y el cobro fallara —sin turno de caja, por ejemplo—, el
 *    huésped quedaría con el folio cerrado en el mostrador y sin forma de
 *    pagarlo.
 */
class HotelBillingEngine
{
    public function __construct(
        protected SaleInvoiceEngine $sales,
        protected DocumentNumberer $numberer,
        protected HotelFolio $folio,
        protected HotelStayEngine $stays,
    ) {}

    /**
     * Factura el folio, registra el pago y cierra la estadía.
     *
     * @param  array{invoice_kind?: string, payment_method?: string, account_id?: int,
     *               paid_amount?: float|null, cash_received?: float|null,
     *               third_party_id?: int|null, reference?: string|null}  $payload
     */
    public function issueForStay(Stay $estadia, array $payload): SaleInvoice
    {
        if (! $estadia->estaAbierta()) {
            throw new RuntimeException('La estadía '.$estadia->fullNumber().' ya está cerrada.');
        }

        if ($estadia->sale_invoice_id) {
            throw new RuntimeException('Esta estadía ya tiene factura emitida.');
        }

        $preparado = $this->folio->paraFacturar($estadia);

        if ($preparado['lineas'] === []) {
            throw new RuntimeException(
                'El folio no tiene nada que facturar. Si la estadía no se va a cobrar, '
                .'ciérrala sin facturar dejando el motivo escrito.'
            );
        }

        if ((float) $estadia->total <= 0) {
            throw new RuntimeException(
                'El folio quedó en cero. Una factura en cero no se emite: '
                .'cierra la estadía sin facturar y queda el motivo.'
            );
        }

        $invoiceKind = in_array($payload['invoice_kind'] ?? 'pos', ['pos', 'electronic'], true)
            ? $payload['invoice_kind']
            : 'pos';

        $paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
        $accountId = (int) ($payload['account_id'] ?? 0);

        if ($accountId <= 0) {
            throw new RuntimeException(
                'No se encontró la cuenta contable para registrar el cobro. '
                .'Revisa el método de pago en Configuración → Métodos de pago.'
            );
        }

        $company = Company::find($estadia->company_id);

        if (! $company) {
            throw new RuntimeException('Sin empresa asociada.');
        }

        $location = $this->resolverSede($estadia);

        if (! $location) {
            throw new RuntimeException(
                'El hotel no tiene sede asignada para facturar. '
                .'Configura «Sede de facturación» en la ficha del hotel.'
            );
        }

        $customer = $this->resolverCliente($company, $payload['third_party_id'] ?? $estadia->third_party_id);

        // Turno de caja obligatorio: la venta tiene que quedar en el cierre de
        // alguien. Sin esto, el arqueo del turno nunca cuadra contra lo
        // facturado.
        $cashSession = $this->turnoAbierto();

        if (! $cashSession) {
            throw new RuntimeException(
                'No tienes un turno de caja abierto. Abre tu caja antes de cobrar un check-out.'
            );
        }

        $paidAmount = isset($payload['paid_amount']) && $payload['paid_amount'] !== null
            ? (float) $payload['paid_amount']
            : (float) $estadia->total;

        $invoice = DB::transaction(function () use (
            $estadia, $company, $location, $customer, $cashSession,
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
                'dian_resolution_id' => $doc['resolution_id'],
                'date' => now()->toDateString(),
                'currency' => 'COP',
                'status' => 'draft',
                'payment_status' => 'pendiente',
                // Las rebajas del folio, sobre la base gravable.
                'global_discount_type' => GlobalDiscount::TYPE_AMOUNT,
                'global_discount_value' => $preparado['descuento_base'],
                'created_by_user_id' => Auth::id(),
                'seller_user_id' => Auth::id(),
                'description' => sprintf(
                    'Folio %s — hab. %s — %s',
                    $estadia->fullNumber(),
                    $estadia->room?->number ?? '—',
                    $estadia->nombreDelHuesped(),
                ),
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
                    // El folio guarda el precio al público y aquí se entrega
                    // ya descompuesto, para que no haya dos cálculos de IVA
                    // que puedan discrepar.
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

            // post() recalcula totales (reparte el descuento global), crea los
            // asientos, descarga inventario de los productos y aplica los
            // anticipos que el huésped tenga a favor.
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
                    'reference' => $payload['reference'] ?? ('Folio '.$estadia->fullNumber()),
                    'description' => 'Check-out '.$invoice->fullNumber(),
                ]);
            }

            $invoice = $invoice->fresh();

            $this->stays->cerrarFacturada(
                $estadia,
                (int) $invoice->id,
                (float) $invoice->paid_amount,
                (int) $cashSession->id,
            );

            return $invoice;
        });

        // La electrónica va fuera de la transacción: es una llamada HTTP de
        // varios segundos y adentro mantendría los locks abiertos. Se espera
        // la respuesta para que la cuenta salga con CUFE y QR.
        app(PosDianTransmitter::class)->transmit($invoice);

        return $invoice->fresh();
    }

    /**
     * Registra un depósito o anticipo de la estadía.
     *
     * No se invita nada nuevo: entra como anticipo de cliente, que es la
     * figura que el sistema ya tiene y que ya entra al turno de caja. Al
     * facturar el check-out, SaleInvoiceEngine lo aplica solo —el anticipo se
     * cruza contra la factura siguiente del cliente— y el huésped solo paga la
     * diferencia.
     *
     * Exige un cliente con nombre: a Consumidor Final no se le puede guardar
     * un saldo a favor, porque no hay a quién devolvérselo ni contra qué
     * cruzarlo.
     *
     * @param  array{amount: float, payment_method?: string, account_id?: int,
     *               reference?: string|null, notes?: string|null}  $payload
     */
    public function registrarDeposito(Stay $estadia, array $payload): CustomerAdvance
    {
        if (! $estadia->estaAbierta()) {
            throw new RuntimeException('Solo se recibe depósito de una estadía abierta.');
        }

        $customer = $estadia->third_party_id
            ? ThirdParty::query()->where('company_id', $estadia->company_id)->find($estadia->third_party_id)
            : null;

        if (! $customer) {
            throw new RuntimeException(
                'Para recibir un depósito hay que asignarle un cliente a la estadía: '
                .'un saldo a favor sin dueño no se puede cruzar con nada ni devolver.'
            );
        }

        $cashSession = $this->turnoAbierto();

        if (! $cashSession) {
            throw new RuntimeException(
                'No tienes un turno de caja abierto. Abre tu caja antes de recibir un depósito.'
            );
        }

        return app(CustomerAdvanceService::class)->register($customer, [
            'amount' => $payload['amount'],
            'payment_method' => $payload['payment_method'] ?? 'cash',
            'account_id' => $payload['account_id'] ?? null,
            'cash_register_session_id' => $cashSession->id,
            'reference' => $payload['reference'] ?? ('Depósito folio '.$estadia->fullNumber()),
            'notes' => $payload['notes'] ?? ('Hab. '.($estadia->room?->number ?? '—')),
        ]);
    }

    /**
     * Turno abierto del usuario actual.
     *
     * No está amarrado a la sede de la factura: un recepcionista puede operar
     * varias y el turno es de la persona, no del mostrador.
     */
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

    protected function resolverSede(Stay $estadia): ?Location
    {
        $hotel = $estadia->hotel()->with('defaultLocation')->first();

        if ($hotel?->defaultLocation) {
            return $hotel->defaultLocation;
        }

        return Location::query()
            ->where('company_id', $estadia->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->first();
    }

    protected function resolverCliente(Company $company, ?int $thirdPartyId): ThirdParty
    {
        if ($thirdPartyId) {
            $tercero = ThirdParty::query()->where('company_id', $company->id)->find($thirdPartyId);

            if ($tercero) {
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
}
