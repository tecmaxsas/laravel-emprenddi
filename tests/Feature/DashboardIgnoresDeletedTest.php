<?php

namespace Tests\Feature;

use App\Filament\App\Widgets\DashboardOverviewWidget;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\PlanLimitChecker;
use App\Services\Sales\SaleInvoiceDeleter;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El escritorio no puede contar facturas borradas.
 *
 * El escritorio consulta con `DB::table()` por velocidad —son una docena de
 * agregados y no hace falta hidratar modelos—, pero eso significa que no pasa
 * por Eloquent y **no hereda ninguno de los filtros automáticos**: ni el de
 * empresa ni el de borrados.
 *
 * Al poder borrar facturas POS, las borradas siguieron sumando en «Ventas del
 * mes» y en «Por cobrar». El usuario veía una cartera que no existía y un conteo
 * de facturas que no cuadraba con el listado — y no hay forma de que adivine por
 * qué, porque las facturas ya no aparecen en ninguna pantalla.
 *
 * Siete de las nueve consultas no filtraban `deleted_at`.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class DashboardIgnoresDeletedTest extends TestCase
{
    private Company $company;

    private Location $sede;

    private Product $producto;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

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

    /** Borrar una venta la descuenta de los totales del escritorio. */
    public function test_borrar_una_venta_la_saca_del_escritorio(): void
    {
        $antes = $this->ventas();

        $factura = $this->ventaPos(total: 2147500);

        $conLaVenta = $this->ventas();

        $this->assertEqualsWithDelta($antes['month'] + 2147500, $conLaVenta['month'], 0.01,
            'La venta debió sumar al mes.');
        $this->assertSame($antes['invoices_month'] + 1, $conLaVenta['invoices_month']);

        app(SaleInvoiceDeleter::class)->delete($factura, 'prueba');

        $despues = $this->ventas();

        $this->assertEqualsWithDelta($antes['month'], $despues['month'], 0.01,
            'Una factura borrada no puede seguir sumando en «Ventas del mes».');
        $this->assertSame($antes['invoices_month'], $despues['invoices_month'],
            'Ni contarse en «facturas posteadas»: el número no cuadraría con el listado.');
    }

    /** Y de la cartera, que es lo que más engaña. */
    public function test_la_cartera_no_incluye_facturas_borradas(): void
    {
        $antes = $this->ventas()['receivables'];

        $factura = $this->ventaPos(total: 2147500);

        $this->assertGreaterThan($antes, $this->ventas()['receivables'],
            'Sin pagar, la venta debió sumar a la cartera.');

        app(SaleInvoiceDeleter::class)->delete($factura, 'prueba');

        $this->assertEqualsWithDelta($antes, $this->ventas()['receivables'], 0.01,
            'Una cartera que incluye facturas borradas es plata que nadie va a cobrar.');
    }

    /** El cupo del plan tampoco se gasta con lo que ya se borró. */
    public function test_el_plan_no_cuenta_facturas_borradas(): void
    {
        $metodo = new ReflectionMethod(PlanLimitChecker::class, 'countCurrent');
        $metodo->setAccessible(true);

        $uso = fn () => $metodo->invoke(
            app(PlanLimitChecker::class),
            $this->company,
            'max_invoices_per_month',
        );

        $antes = $uso();

        $factura = $this->ventaPos(total: 100000);
        $this->assertSame($antes + 1, $uso());

        app(SaleInvoiceDeleter::class)->delete($factura, 'prueba');

        $this->assertSame($antes, $uso(),
            'El cliente pagaría cupo por una factura que ya borró, sin manera de notarlo.');
    }

    /**
     * Ninguna consulta del escritorio puede saltarse el filtro de borrados.
     *
     * Esta es la que de verdad protege el arreglo. Poner el `whereNull` a mano
     * en cada consulta es exactamente como apareció el fallo: había nueve, dos
     * lo tenían y siete no. Mientras todas salgan del mismo método, arreglarlo
     * una vez alcanza.
     */
    public function test_todas_las_consultas_salen_del_metodo_con_el_filtro(): void
    {
        $ruta = app_path('Filament/App/Widgets/DashboardOverviewWidget.php');
        $contenido = file_get_contents($ruta);

        // Se quitan los comentarios: mencionar `DB::table` al explicar por qué
        // existe el método no es usarlo.
        $codigo = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $contenido);

        $usos = preg_match_all('/DB::table\(/', $codigo);

        $this->assertSame(1, $usos,
            "El escritorio tiene {$usos} llamadas a DB::table y debe tener exactamente una, "
            .'dentro de facturas(). Cualquier otra se salta el filtro de borrados y vuelve a '
            .'sumar facturas eliminadas en los indicadores.');
    }

    // --------------------------------------------------------- auxiliares

    /** @return array{month: float, invoices_month: int, receivables: float} */
    private function ventas(): array
    {
        $metodo = new ReflectionMethod(DashboardOverviewWidget::class, 'salesData');
        $metodo->setAccessible(true);

        return $metodo->invoke(new DashboardOverviewWidget, $this->company->id);
    }

    private function ventaPos(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZDASH',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'pos',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        SaleInvoiceLine::withoutGlobalScopes()->create([
            'sale_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 1,
            'unit_price' => $total,
            'cost_at_sale' => $total / 2,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            $asientos = DB::table('journal_entries')
                ->where('company_id', $this->company->id)
                ->where('reference', 'like', '%ZZDASH%')
                ->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
        };

        return app(SaleInvoiceEngine::class)->post($factura->fresh(['lines']), allowNegativeStock: true);
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE ESCRITORIO',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZDASH'.random_int(10000, 99999),
            'name' => 'ZZ Producto escritorio',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => true,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'default_purchase_price' => 50000,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('inventory_movements')->where('product_id', $this->producto->id)->delete();
            DB::table('product_locations')->where('product_id', $this->producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }
}
