<?php

namespace Tests\Feature;

use App\Filament\App\Widgets\DashboardOverviewWidget;
use App\Models\Company;
use App\Models\Location;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\DashboardSections;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La sección de vencimientos del escritorio.
 *
 * Responde lo que las tarjetas de «por cobrar» y «por pagar» no responden: no
 * cuánto suman, sino a quién hay que llamar hoy. Por eso lo que se prueba aquí
 * es sobre todo el cálculo de los días —una factura vencida hace tres meses no
 * puede aparecer como «vence en 90 días»— y que no entren facturas que no
 * corresponden.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class DashboardDueInvoicesTest extends TestCase
{
    private Company $company;

    private User $user;

    private ThirdParty $tercero;

    private int $locationId;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => $u->can('sales.view') && $u->can('purchases.view'))
            ?? $this->markTestSkipped('Ningún usuario de la base ve ventas y compras.');

        $this->company = Company::findOrFail($this->user->company_id);
        $this->locationId = (int) Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->value('id');

        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->tercero = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ TERCERO VENCIMIENTOS',
            'is_customer' => true,
            'is_supplier' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($this->tercero->id)->forceDelete();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** Una factura vencida se cuenta como vencida, con sus días. */
    public function test_una_factura_vencida_muestra_cuantos_dias_lleva(): void
    {
        $factura = $this->facturaVenta(vence: now()->subDays(45), total: 500000);

        $fila = $this->filaDe('sales', $factura->id);

        $this->assertNotNull($fila, 'La factura vencida no apareció en el escritorio.');
        $this->assertTrue($fila['vencida']);
        $this->assertSame(-45, $fila['dias'],
            'Los días van con signo: negativo es «vencida hace tanto».');
        $this->assertEqualsWithDelta(500000, $fila['saldo'], 0.01);
    }

    /** Una que aún no vence muestra cuántos días le faltan. */
    public function test_una_factura_por_vencer_muestra_los_dias_que_faltan(): void
    {
        $factura = $this->facturaVenta(vence: now()->addDays(3), total: 200000);

        $fila = $this->filaDe('sales', $factura->id);

        $this->assertNotNull($fila);
        $this->assertFalse($fila['vencida']);
        $this->assertSame(3, $fila['dias']);
    }

    /** La que vence hoy no es ni vencida ni futura: es hoy. */
    public function test_la_que_vence_hoy_queda_en_cero_dias(): void
    {
        $factura = $this->facturaVenta(vence: now(), total: 100000);

        $this->assertSame(0, $this->filaDe('sales', $factura->id)['dias']);
    }

    /** Lo que vence más allá de la ventana no distrae en el escritorio. */
    public function test_lo_que_vence_lejos_no_aparece(): void
    {
        $factura = $this->facturaVenta(vence: now()->addDays(60), total: 900000);

        $this->assertNull($this->filaDe('sales', $factura->id),
            'Una factura que vence en dos meses no es un pendiente de hoy.');
    }

    /** Una factura ya pagada no es un pendiente. */
    public function test_una_factura_pagada_no_aparece(): void
    {
        $factura = $this->facturaVenta(vence: now()->subDays(10), total: 300000, pagado: 300000);

        $this->assertNull($this->filaDe('sales', $factura->id));
    }

    /** Una de contado no se vence: sin fecha de vencimiento no entra. */
    public function test_una_factura_sin_fecha_de_vencimiento_no_aparece(): void
    {
        $factura = $this->facturaVenta(vence: null, total: 400000);

        $this->assertNull($this->filaDe('sales', $factura->id));
    }

    /** Un borrador todavía no debe nada. */
    public function test_una_factura_en_borrador_no_aparece(): void
    {
        $factura = $this->facturaVenta(vence: now()->subDays(5), total: 250000, estado: SaleInvoice::STATUS_DRAFT);

        $this->assertNull($this->filaDe('sales', $factura->id));
    }

    /** Las compras se calculan igual y van en su propio bloque. */
    public function test_las_compras_vencidas_van_en_su_bloque(): void
    {
        $compra = $this->facturaCompra(vence: now()->subDays(12), total: 700000);

        $fila = $this->filaDe('purchases', $compra->id);

        $this->assertNotNull($fila, 'La compra vencida no apareció.');
        $this->assertSame(-12, $fila['dias']);
        $this->assertNull($this->filaDe('sales', $compra->id),
            'Una compra no puede aparecer entre las ventas.');
    }

    /** Los totales cuentan todo, aunque la tabla liste solo unas pocas. */
    public function test_los_totales_cuentan_mas_alla_de_lo_que_se_lista(): void
    {
        $antes = $this->datos()['sales'];

        $this->facturaVenta(vence: now()->subDays(20), total: 111000);
        $this->facturaVenta(vence: now()->subDays(21), total: 222000);

        $despues = $this->datos()['sales'];

        $this->assertSame($antes['vencidas'] + 2, $despues['vencidas']);
        $this->assertEqualsWithDelta($antes['monto_vencido'] + 333000, $despues['monto_vencido'], 0.01);
    }

    /** El escritorio se pinta con la sección adentro. */
    public function test_el_escritorio_muestra_la_seccion(): void
    {
        $this->facturaVenta(vence: now()->subDays(7), total: 150000);

        Livewire::test(DashboardOverviewWidget::class)
            ->assertOk()
            ->assertSee('Vencimientos')
            ->assertSee('Vencida hace 7 días');
    }

    /** La sección se puede apagar desde «Personalizar escritorio». */
    public function test_la_seccion_es_personalizable(): void
    {
        $this->assertArrayHasKey('due_invoices', DashboardSections::SECTIONS);
        $this->assertArrayHasKey('due_invoices', DashboardSections::availableFor($this->user));
    }

    // --------------------------------------------------------- auxiliares

    /** @return array<string, mixed> */
    private function datos(): array
    {
        return Livewire::test(DashboardOverviewWidget::class)
            ->instance()
            ->getViewData()['dueInvoices'];
    }

    /** @return array<string, mixed>|null */
    private function filaDe(string $bloque, int $facturaId): ?array
    {
        return collect($this->datos()[$bloque]['filas'] ?? [])
            ->firstWhere('id', $facturaId);
    }

    private function facturaVenta(?Carbon $vence, float $total, float $pagado = 0, ?string $estado = null): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->locationId,
            'third_party_id' => $this->tercero->id,
            'prefix' => 'ZZV',
            'number' => random_int(100000, 999999),
            'date' => now()->subDays(30)->toDateString(),
            'due_date' => $vence?->toDateString(),
            'currency' => 'COP',
            'status' => $estado ?? SaleInvoice::STATUS_POSTED,
            'payment_status' => $pagado >= $total ? 'pagado' : 'pendiente',
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
            'paid_amount' => $pagado,
        ]);

        $this->limpiar[] = fn () => SaleInvoice::withoutGlobalScopes()
            ->whereKey($factura->id)->forceDelete();

        return $factura;
    }

    private function facturaCompra(?Carbon $vence, float $total): PurchaseInvoice
    {
        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->locationId,
            'third_party_id' => $this->tercero->id,
            'prefix' => 'ZZC',
            'number' => random_int(100000, 999999),
            'supplier_invoice_number' => 'ZZ'.random_int(10000, 99999),
            'date' => now()->subDays(30)->toDateString(),
            'due_date' => $vence?->toDateString(),
            'currency' => 'COP',
            'status' => PurchaseInvoice::STATUS_POSTED,
            'payment_status' => 'pendiente',
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = function () use ($factura) {
            DB::table('purchase_invoices')->where('id', $factura->id)->delete();
        };

        return $factura;
    }
}
