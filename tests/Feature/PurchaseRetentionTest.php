<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryLine;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoiceRetention;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retenciones en las facturas de compra.
 *
 * No existían: ni la tabla, ni la contabilización, ni la pantalla. Una empresa
 * que le retiene a sus proveedores tenía que registrar la factura por el total
 * y arreglar la retención con un asiento manual.
 *
 * La dirección es la contraria a la de venta: aquí nosotros retenemos, así que
 * el monto es un pasivo con la DIAN y al proveedor se le paga menos.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PurchaseRetentionTest extends TestCase
{
    private Company $company;

    private ThirdParty $proveedor;

    private Product $producto;

    private Tax $retefuente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->prepararCatalogo();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** La retención sale del neto a pagar, no del total de la factura. */
    public function test_la_retencion_baja_el_neto_a_pagar_sin_tocar_el_total(): void
    {
        $factura = $this->facturaConRetencion(base: 1000000, tarifa: 2.5);

        app(PurchaseInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh();

        $this->assertEqualsWithDelta(1000000, (float) $factura->total, 0.01,
            'El total de la factura no cambia: la retención no es un menor valor de la compra.');
        $this->assertEqualsWithDelta(25000, (float) $factura->retention_total, 0.01);
        $this->assertEqualsWithDelta(975000, (float) $factura->net_payable, 0.01);
    }

    /** El saldo pendiente se mide contra lo que se le debe al proveedor. */
    public function test_el_saldo_pendiente_usa_el_neto(): void
    {
        $factura = $this->facturaConRetencion(base: 1000000, tarifa: 2.5);
        app(PurchaseInvoiceEngine::class)->recalculateTotals($factura);

        $this->assertEqualsWithDelta(975000, (float) $factura->fresh()->balance, 0.01,
            'Con el total, la factura quedaría eternamente en parcial: al proveedor nunca se le paga eso.');
    }

    /**
     * El asiento tiene que cuadrar y repartir bien: la cuenta por pagar baja
     * al neto y la retención se acredita a su propia cuenta.
     */
    public function test_el_asiento_acredita_la_retencion_aparte_de_la_cuenta_por_pagar(): void
    {
        $factura = $this->facturaConRetencion(base: 1000000, tarifa: 2.5);

        $contabilizada = app(PurchaseInvoiceEngine::class)->post($factura);

        $lineas = JournalEntryLine::query()
            ->where('journal_entry_id', $contabilizada->journal_entry_id)
            ->get();

        $debitos = round((float) $lineas->sum('debit'), 2);
        $creditos = round((float) $lineas->sum('credit'), 2);

        $this->assertEqualsWithDelta($debitos, $creditos, 0.01, 'El asiento no cuadra.');
        $this->assertEqualsWithDelta(1000000, $debitos, 0.01);

        $lineaRetencion = $lineas->firstWhere('account_id', $this->retefuente->purchase_account_id);
        $this->assertNotNull($lineaRetencion, 'Falta la línea de la retención.');
        $this->assertEqualsWithDelta(25000, (float) $lineaRetencion->credit, 0.01);

        $porPagar = $lineas->where('credit', '>', 0)
            ->firstWhere('account_id', '!=', $this->retefuente->purchase_account_id);
        $this->assertEqualsWithDelta(975000, (float) $porPagar->credit, 0.01,
            'A la cuenta por pagar solo va lo que de verdad se le debe al proveedor.');
    }

    /** Sin retenciones, el neto es el total: nada cambia para las de siempre. */
    public function test_una_factura_sin_retenciones_no_cambia(): void
    {
        $factura = $this->facturaConRetencion(base: 500000, tarifa: null);

        app(PurchaseInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh();

        $this->assertEqualsWithDelta(0, (float) $factura->retention_total, 0.01);
        $this->assertEqualsWithDelta((float) $factura->total, (float) $factura->net_payable, 0.01);
        $this->assertEqualsWithDelta((float) $factura->total, (float) $factura->balance, 0.01);
    }

    /**
     * Sin cuenta configurada el asiento quedaría descuadrado o mudo. Se corta
     * antes, diciendo qué retención y dónde arreglarla.
     */
    public function test_una_retencion_sin_cuenta_no_se_contabiliza(): void
    {
        $cuentaOriginal = $this->retefuente->purchase_account_id;
        $this->retefuente->update(['purchase_account_id' => null]);
        $this->limpiar[] = fn () => $this->retefuente->update(['purchase_account_id' => $cuentaOriginal]);

        $factura = $this->facturaConRetencion(base: 1000000, tarifa: 2.5);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cuenta de compra/');

        app(PurchaseInvoiceEngine::class)->post($factura);
    }

    private function facturaConRetencion(float $base, ?float $tarifa): PurchaseInvoice
    {
        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => Location::withoutGlobalScopes()
                ->where('company_id', $this->company->id)->value('id'),
            'third_party_id' => $this->proveedor->id,
            'prefix' => 'ZZC',
            'number' => random_int(100000, 999999),
            'supplier_invoice_number' => 'ZZ'.random_int(10000, 99999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => PurchaseInvoice::STATUS_DRAFT,
            'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
        ]);

        PurchaseInvoiceLine::withoutGlobalScopes()->create([
            'purchase_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 1,
            'unit_cost' => $base,
            'subtotal' => $base,
            'total' => $base,
        ]);

        if ($tarifa !== null) {
            PurchaseInvoiceRetention::create([
                'purchase_invoice_id' => $factura->id,
                'tax_id' => $this->retefuente->id,
                'tax_code' => $this->retefuente->code,
                'tax_name' => $this->retefuente->name,
                'tax_type' => $this->retefuente->type,
                'base_amount' => $base,
                'rate' => $tarifa,
                'amount' => round($base * $tarifa / 100, 2),
            ]);
        }

        $this->limpiar[] = function () use ($factura) {
            $asiento = $factura->fresh()?->journal_entry_id;
            DB::table('journal_entry_lines')->where('journal_entry_id', $asiento)->delete();
            DB::table('journal_entries')->where('id', $asiento)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)->delete();
            DB::table('purchase_invoice_retentions')->where('purchase_invoice_id', $factura->id)->delete();
            DB::table('purchase_invoice_lines')->where('purchase_invoice_id', $factura->id)->delete();
            DB::table('purchase_invoices')->where('id', $factura->id)->delete();
        };

        return $factura->fresh(['lines', 'retentions']);
    }

    private function prepararCatalogo(): void
    {
        $this->proveedor = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ PROVEEDOR RETENCION',
            'is_supplier' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZRET'.random_int(10000, 99999),
            'name' => 'ZZ Producto compra',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_purchasable' => true,
            'default_purchase_price' => 100000,
            'active' => true,
        ]);

        // Retención en la fuente: su cuenta es un pasivo, lo que le debemos a
        // la DIAN por lo retenido.
        // Distinta de la cuenta por pagar a proposito: si fueran la misma, el
        // asiento cuadraria igual pero la prueba no podria distinguir una
        // linea de la otra —y en la practica tampoco un contador—.
        $cuenta = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('accepts_movements', true)
            ->where('code', 'not like', '2205%')
            ->whereIn('code', ['236540', '2365', '2367', '2368'])
            ->orderByRaw('length(code) desc')
            ->value('id')
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->where('accepts_movements', true)
                ->where('code', 'not like', '2205%')
                ->orderBy('code')
                ->value('id');

        $this->retefuente = Tax::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZRTF'.random_int(100, 999),
            'name' => 'ZZ Retefuente compras',
            'type' => 'income_withholding',
            'applies_to' => 'purchase',
            'rate' => 2.5,
            'purchase_account_id' => $cuenta,
            'is_active' => true,
        ]);

        $this->limpiar[] = function () {
            Tax::withoutGlobalScopes()->whereKey($this->retefuente->id)->forceDelete();
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->proveedor->id)->forceDelete();
        };
    }
}
