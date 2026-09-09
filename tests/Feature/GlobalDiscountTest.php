<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Invoicing\GlobalDiscount;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use App\Support\DiscountSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El descuento global de pie de factura.
 *
 * Lo que se prueba aquí, sobre todo, es que **se aplique sobre la base y no
 * sobre el total**. Con $1.000.000 e IVA del 19 %, un 10 % de descuento mal
 * aplicado cobra $19.000 de IVA de más: plata que la empresa le paga a la DIAN
 * y que no le cobró a nadie, en cada factura.
 *
 * Lo segundo es la idempotencia. El descuento se reparte sumándolo al
 * `discount_amount` de cada línea —para que los doce lugares que calculan la
 * base como `subtotal - discount_amount` queden bien sin tocarlos— y eso obliga
 * a que recalcular dos veces no descuente dos veces.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class GlobalDiscountTest extends TestCase
{
    private Company $company;

    private ThirdParty $tercero;

    private Product $producto;

    private int $locationId;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->locationId = (int) Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->value('id');

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

    // ------------------------------------------------- sobre la base

    /**
     * La prueba que justifica todo el módulo: el IVA se calcula DESPUÉS del
     * descuento.
     */
    public function test_el_iva_se_calcula_sobre_la_base_ya_descontada(): void
    {
        $factura = $this->facturaVenta(base: 1000000, tasaIva: 19);
        $factura->update([
            'global_discount_type' => GlobalDiscount::TYPE_PERCENT,
            'global_discount_value' => 10,
        ]);

        app(SaleInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh();

        $this->assertEqualsWithDelta(1000000, (float) $factura->subtotal, 0.01,
            'El subtotal no cambia: el descuento no altera el precio de lista.');
        $this->assertEqualsWithDelta(100000, (float) $factura->global_discount_amount, 0.01);

        // Base 900.000 × 19 % = 171.000. Sobre el total serían 190.000.
        $this->assertEqualsWithDelta(171000, (float) $factura->tax_total, 0.01,
            'El IVA se está calculando antes del descuento: son $19.000 de más por factura.');

        $this->assertEqualsWithDelta(1071000, (float) $factura->total, 0.01);
    }

    /** Un valor fijo se comporta igual que el porcentaje equivalente. */
    public function test_un_descuento_por_valor_fijo_da_lo_mismo_que_su_porcentaje(): void
    {
        $factura = $this->facturaVenta(base: 1000000, tasaIva: 19);
        $factura->update([
            'global_discount_type' => GlobalDiscount::TYPE_AMOUNT,
            'global_discount_value' => 100000,
        ]);

        app(SaleInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh();

        $this->assertEqualsWithDelta(100000, (float) $factura->global_discount_amount, 0.01);
        $this->assertEqualsWithDelta(171000, (float) $factura->tax_total, 0.01);
        $this->assertEqualsWithDelta(1071000, (float) $factura->total, 0.01);
    }

    /** No se puede descontar más de lo que vale la factura. */
    public function test_un_descuento_mayor_a_la_factura_se_recorta(): void
    {
        $factura = $this->facturaVenta(base: 500000, tasaIva: 19);
        $factura->update([
            'global_discount_type' => GlobalDiscount::TYPE_AMOUNT,
            'global_discount_value' => 900000,
        ]);

        app(SaleInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh();

        $this->assertEqualsWithDelta(500000, (float) $factura->global_discount_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $factura->total, 0.01,
            'Una factura no puede quedar en negativo.');
    }

    // --------------------------------------------------- idempotencia

    /** Recalcular dos veces no descuenta dos veces. */
    public function test_recalcular_varias_veces_no_acumula_el_descuento(): void
    {
        $factura = $this->facturaVenta(base: 1000000, tasaIva: 19);
        $factura->update([
            'global_discount_type' => GlobalDiscount::TYPE_PERCENT,
            'global_discount_value' => 10,
        ]);

        $motor = app(SaleInvoiceEngine::class);

        $motor->recalculateTotals($factura);
        $motor->recalculateTotals($factura->fresh());
        $motor->recalculateTotals($factura->fresh());

        $factura->refresh();

        $this->assertEqualsWithDelta(100000, (float) $factura->global_discount_amount, 0.01);
        $this->assertEqualsWithDelta(1071000, (float) $factura->total, 0.01);
    }

    /** Quitar el descuento devuelve la factura a como estaba. */
    public function test_quitar_el_descuento_restituye_los_totales(): void
    {
        $factura = $this->facturaVenta(base: 1000000, tasaIva: 19);
        $motor = app(SaleInvoiceEngine::class);

        $factura->update(['global_discount_type' => GlobalDiscount::TYPE_PERCENT, 'global_discount_value' => 10]);
        $motor->recalculateTotals($factura);

        $factura->fresh()->update(['global_discount_value' => 0]);
        $motor->recalculateTotals($factura->fresh());

        $factura->refresh();

        $this->assertEqualsWithDelta(0, (float) $factura->global_discount_amount, 0.01);
        $this->assertEqualsWithDelta(190000, (float) $factura->tax_total, 0.01);
        $this->assertEqualsWithDelta(1190000, (float) $factura->total, 0.01);
    }

    // ------------------------------------------------------- reparto

    /**
     * Con dos líneas de tarifas distintas, el descuento se reparte
     * proporcionalmente y cada una paga su propio IVA sobre lo que le quedó.
     */
    public function test_el_reparto_respeta_la_tarifa_de_cada_linea(): void
    {
        $factura = $this->facturaVenta(base: 600000, tasaIva: 19);
        $this->agregarLinea($factura, base: 400000, tasaIva: 0);

        $factura->update([
            'global_discount_type' => GlobalDiscount::TYPE_PERCENT,
            'global_discount_value' => 10,
        ]);

        app(SaleInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh()->load('lines');

        $gravada = $factura->lines->firstWhere('tax_rate', 19);
        $excluida = $factura->lines->firstWhere('tax_rate', 0);

        $this->assertEqualsWithDelta(60000, (float) $gravada->global_discount_amount, 0.01,
            'A la línea de 600.000 le toca el 60 % del descuento.');
        $this->assertEqualsWithDelta(40000, (float) $excluida->global_discount_amount, 0.01);

        // 540.000 × 19 % = 102.600. La excluida no paga IVA.
        $this->assertEqualsWithDelta(102600, (float) $factura->tax_total, 0.01);
        $this->assertEqualsWithDelta(100000, (float) $factura->global_discount_amount, 0.01);
    }

    /** El redondeo del reparto no puede perder ni inventar pesos. */
    public function test_el_reparto_cuadra_al_peso(): void
    {
        $factura = $this->facturaVenta(base: 33333, tasaIva: 19);
        $this->agregarLinea($factura, base: 33333, tasaIva: 19);
        $this->agregarLinea($factura, base: 33334, tasaIva: 19);

        $factura->update([
            'global_discount_type' => GlobalDiscount::TYPE_AMOUNT,
            'global_discount_value' => 10000,
        ]);

        app(SaleInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh()->load('lines');

        $this->assertEqualsWithDelta(10000, (float) $factura->lines->sum('global_discount_amount'), 0.01,
            'Lo repartido entre las líneas tiene que sumar exactamente lo pactado.');
    }

    /** El descuento de línea y el global conviven sin pisarse. */
    public function test_convive_con_el_descuento_de_linea(): void
    {
        $factura = $this->facturaVenta(base: 1000000, tasaIva: 19, descuentoLinea: 100000);
        $factura->update([
            'global_discount_type' => GlobalDiscount::TYPE_PERCENT,
            'global_discount_value' => 10,
        ]);

        app(SaleInvoiceEngine::class)->recalculateTotals($factura);
        $factura->refresh()->load('lines');

        // Base tras el descuento de línea: 900.000. El global es el 10 % de eso.
        $this->assertEqualsWithDelta(90000, (float) $factura->global_discount_amount, 0.01,
            'El global se calcula sobre la base ya neta de los descuentos de línea.');
        $this->assertEqualsWithDelta(190000, (float) $factura->discount_total, 0.01,
            'El descuento total suma el de línea y el global.');
        $this->assertEqualsWithDelta(153900, (float) $factura->tax_total, 0.01);
    }

    // --------------------------------------------------------- compras

    /** En compras funciona igual: baja el costo y el IVA descontable. */
    public function test_en_compras_baja_la_base_y_el_iva(): void
    {
        $compra = $this->facturaCompra(base: 1000000, tasaIva: 19);
        $compra->update([
            'global_discount_type' => GlobalDiscount::TYPE_PERCENT,
            'global_discount_value' => 10,
        ]);

        app(PurchaseInvoiceEngine::class)->recalculateTotals($compra);
        $compra->refresh();

        $this->assertEqualsWithDelta(100000, (float) $compra->global_discount_amount, 0.01);
        $this->assertEqualsWithDelta(171000, (float) $compra->tax_total, 0.01);
        $this->assertEqualsWithDelta(1071000, (float) $compra->total, 0.01);
    }

    // ---------------------------------------------------- configuración

    /** Apagado por defecto: es una decisión comercial, no un campo más. */
    public function test_viene_apagado_y_se_enciende_por_configuracion(): void
    {
        $originales = $this->company->settings;
        $this->limpiar[] = fn () => DB::table('companies')
            ->where('id', $this->company->id)
            ->update(['settings' => json_encode($originales)]);

        $ajustes = $originales ?? [];
        unset($ajustes['discounts']);
        $this->company->update(['settings' => $ajustes]);
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->assertFalse(DiscountSettings::allowsOnSaleInvoices());
        $this->assertFalse(DiscountSettings::allowsOnPurchaseInvoices());

        $ajustes['discounts'] = ['sales' => true, 'purchases' => false];
        $this->company->update(['settings' => $ajustes]);
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->assertTrue(DiscountSettings::allowsOnSaleInvoices());
        $this->assertFalse(DiscountSettings::allowsOnPurchaseInvoices(),
            'Los dos ajustes son independientes.');
    }

    // ------------------------------------------------------ auxiliares

    private function facturaVenta(float $base, float $tasaIva, float $descuentoLinea = 0): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->locationId,
            'third_party_id' => $this->tercero->id,
            'prefix' => 'ZZD',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
        ]);

        $this->limpiar[] = function () use ($factura) {
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            SaleInvoice::withoutGlobalScopes()->whereKey($factura->id)->forceDelete();
        };

        $this->agregarLinea($factura, $base, $tasaIva, $descuentoLinea);

        return $factura->fresh(['lines']);
    }

    private function agregarLinea(SaleInvoice $factura, float $base, float $tasaIva, float $descuentoLinea = 0): void
    {
        $gravable = $base - $descuentoLinea;

        SaleInvoiceLine::withoutGlobalScopes()->create([
            'sale_invoice_id' => $factura->id,
            'line_number' => $factura->lines()->count() + 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 1,
            'unit_price' => $base,
            'subtotal' => $base,
            'discount_amount' => $descuentoLinea,
            'tax_rate' => $tasaIva,
            'tax_amount' => round($gravable * $tasaIva / 100, 2),
            'total' => round($gravable * (1 + $tasaIva / 100), 2),
        ]);

        $factura->load('lines');
    }

    private function facturaCompra(float $base, float $tasaIva): PurchaseInvoice
    {
        $compra = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->locationId,
            'third_party_id' => $this->tercero->id,
            'prefix' => 'ZZDC',
            'number' => random_int(100000, 999999),
            'supplier_invoice_number' => 'ZZ'.random_int(10000, 99999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => PurchaseInvoice::STATUS_DRAFT,
            'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
        ]);

        PurchaseInvoiceLine::withoutGlobalScopes()->create([
            'purchase_invoice_id' => $compra->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 1,
            'unit_cost' => $base,
            'subtotal' => $base,
            'tax_rate' => $tasaIva,
            'tax_amount' => round($base * $tasaIva / 100, 2),
            'total' => round($base * (1 + $tasaIva / 100), 2),
        ]);

        $this->limpiar[] = function () use ($compra) {
            DB::table('purchase_invoice_lines')->where('purchase_invoice_id', $compra->id)->delete();
            DB::table('purchase_invoices')->where('id', $compra->id)->delete();
        };

        return $compra->fresh(['lines']);
    }

    private function prepararCatalogo(): void
    {
        $this->tercero = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ TERCERO DESCUENTO',
            'is_customer' => true,
            'is_supplier' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZDSC'.random_int(10000, 99999),
            'name' => 'ZZ Producto descuento',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'is_purchasable' => true,
            'default_sale_price' => 100000,
            'default_purchase_price' => 50000,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->tercero->id)->forceDelete();
        };
    }
}
