<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Reports\SalesByPaymentMethodPage;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Support\CurrentCompany;
use App\Support\PaymentMethodOptions;
use App\Support\SalesByPaymentMethod;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cuánta plata entró por cada forma de pago.
 *
 * Es la pregunta de todo cierre de día: del total vendido, cuánto fue
 * efectivo, cuánto tarjeta, cuánto Nequi. Hasta ahora tocaba abrir cada cierre
 * de caja y sumar a mano.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class SalesByPaymentMethodReportTest extends TestCase
{
    private Company $company;

    private User $user;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);
        PaymentMethodOptions::olvidarCache();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];
        PaymentMethodOptions::olvidarCache();

        parent::tearDown();
    }

    /** Cada forma de pago sale en su propia fila con su total. */
    public function test_suma_cada_metodo_por_separado(): void
    {
        $factura = $this->facturaContabilizada();

        $this->pago($factura, 'cash', 10000);
        $this->pago($factura, 'cash', 5000);
        $this->pago($factura, 'credit_card', 20000);

        $filas = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->keyBy('codigo');

        $this->assertSame(15000.0, $filas['cash']['total'],
            'Dos pagos en efectivo tienen que sumarse en una sola fila.');
        $this->assertSame(2, $filas['cash']['operaciones']);
        $this->assertSame(20000.0, $filas['credit_card']['total']);
    }

    /**
     * Los nombres son los que la empresa configuró.
     *
     * Una empresa que cobra por Nequi lo crea en Configuración → Métodos de
     * pago. Si el reporte leyera solo la lista de fábrica, el dueño vería
     * «nequi» en minúscula o directamente «Otro».
     */
    public function test_usa_los_metodos_de_pago_de_la_empresa(): void
    {
        $metodo = PaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'zz_nequi_test',
            'name' => 'Nequi ZZ',
            'type' => PaymentMethod::TYPES ? array_key_first(PaymentMethod::TYPES) : 'cash',
            'active' => true,
            'sort_order' => 99,
        ]);

        $this->limpiar[] = fn () => DB::table('payment_methods')->where('id', $metodo->id)->delete();
        PaymentMethodOptions::olvidarCache();

        $factura = $this->facturaContabilizada();
        $this->pago($factura, 'zz_nequi_test', 7000);

        $filas = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->keyBy('codigo');

        $this->assertSame('Nequi ZZ', $filas['zz_nequi_test']['metodo'],
            'El dueño tiene que leer el nombre que él le puso, no el código.');
    }

    /**
     * Una factura en borrador no es plata recibida.
     *
     * Contarla inflaría el recaudo del día y el reporte dejaría de cuadrar
     * contra el arqueo de caja.
     */
    public function test_no_cuenta_las_facturas_sin_contabilizar(): void
    {
        $borrador = $this->facturaContabilizada();
        $borrador->update(['status' => SaleInvoice::STATUS_DRAFT]);

        $this->pago($borrador, 'cash', 99000);

        $codigos = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->pluck('codigo');

        $this->assertNotContains('cash', $codigos->all(),
            'Un borrador no representa plata en el cajón.');
    }

    /** Un pago fuera del rango de fechas no entra. */
    public function test_respeta_el_rango_de_fechas(): void
    {
        $factura = $this->facturaContabilizada();

        $this->pago($factura, 'cash', 12345, now()->subMonths(2)->toDateString());

        $total = SalesByPaymentMethod::total($this->filtrosDeHoy());

        $this->assertSame(0.0, $total,
            'Un cobro de hace dos meses no es del período consultado.');
    }

    /**
     * Va por la fecha del PAGO, no por la de la factura.
     *
     * Es la decisión que hace que el total cuadre contra el arqueo: la plata
     * de una venta a crédito entra el día que el cliente paga.
     */
    public function test_va_por_la_fecha_del_pago(): void
    {
        $factura = $this->facturaContabilizada(now()->subMonths(2)->toDateString());

        $this->pago($factura, 'cash', 8000, now()->toDateString());

        $this->assertSame(8000.0, SalesByPaymentMethod::total($this->filtrosDeHoy()),
            'La factura es de hace dos meses pero el cobro es de hoy.');
    }

    /** Los porcentajes reparten el total. */
    public function test_los_porcentajes_reparten_el_total(): void
    {
        $factura = $this->facturaContabilizada();

        $this->pago($factura, 'cash', 75000);
        $this->pago($factura, 'credit_card', 25000);

        $filas = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->keyBy('codigo');

        $this->assertSame(75.0, $filas['cash']['participacion']);
        $this->assertSame(25.0, $filas['credit_card']['participacion']);
    }

    /**
     * Un anticipo recibido cuenta como plata que entró.
     *
     * La primera versión contaba solo lo aplicado a facturas. A un negocio que
     * trabaja con anticipos le mostraba $2.290.200 mientras la caja del mismo
     * período decía $8.315.200: dos pantallas del mismo sistema respondiendo
     * distinto a la misma pregunta.
     */
    public function test_un_anticipo_recibido_cuenta(): void
    {
        $this->anticipo('bank_transfer', 500000);

        $filas = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->keyBy('codigo');

        $this->assertSame(500000.0, $filas['bank_transfer']['total'] ?? null,
            'Esa plata entró, aunque todavía no pague ninguna factura.');
    }

    /**
     * Aplicar un anticipo a una factura no lo cuenta dos veces.
     *
     * Esa operación cruza el pasivo del anticipo contra la cartera: no mueve
     * dinero. El día que entró ya se contó.
     */
    public function test_aplicar_un_anticipo_no_suma_otra_vez(): void
    {
        $factura = $this->facturaContabilizada();
        $anticipo = $this->anticipo('cash', 300000);

        $this->pago($factura, 'advance', 300000, null, $anticipo->id);

        $this->assertSame(300000.0, SalesByPaymentMethod::total($this->filtrosDeHoy()),
            'Los 300.000 entraron una sola vez.');
    }

    /** Un anticipo sin método guardado se agrupa aparte, no se le inventa uno. */
    public function test_un_anticipo_sin_metodo_se_agrupa_en_otro(): void
    {
        $this->anticipo(null, 150000);

        $filas = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->keyBy('codigo');

        $this->assertSame(150000.0, $filas['other']['total'] ?? null);
    }

    /**
     * El reporte cuadra con el cierre de caja del mismo período.
     *
     * Es la razón de ser de todo esto. Si las dos pantallas responden distinto
     * a «cuánta plata entró», el usuario no sabe cuál creer y las dos pierden
     * su valor.
     */
    public function test_cuadra_con_el_cierre_de_caja(): void
    {
        $turno = $this->abrirCaja();

        $factura = $this->facturaContabilizada();
        $this->pago($factura, 'cash', 120000, null, null, $turno->id);
        $this->anticipo('cash', 400000, $turno->id);
        $this->anticipo('bank_transfer', 250000, $turno->id);

        $caja = app(CashSessionSummary::class)->compute($turno->fresh());
        $reporte = SalesByPaymentMethod::total($this->filtrosDeHoy());

        $this->assertSame(round($caja['sales']['total'], 2), round($reporte, 2),
            'Las dos pantallas responden la misma pregunta: tienen que dar lo mismo.');
    }

    /** Sin pagos no revienta ni divide por cero. */
    public function test_un_periodo_sin_pagos_no_revienta(): void
    {
        $filtros = [
            'from' => now()->addYears(5)->toDateString(),
            'to' => now()->addYears(5)->addDay()->toDateString(),
            'location_id' => null,
            'created_by_user_id' => null,
        ];

        $this->assertSame([], SalesByPaymentMethod::filas($filtros));
        $this->assertSame(0.0, SalesByPaymentMethod::total($filtros));
        $this->assertSame(0, SalesByPaymentMethod::operaciones($filtros));
    }

    // ------------------------------------------------ pantalla y exportación

    /** La pantalla abre y muestra los métodos con plata. */
    public function test_la_pantalla_muestra_los_metodos(): void
    {
        $factura = $this->facturaContabilizada();
        $this->pago($factura, 'cash', 33000);

        Livewire::test(SalesByPaymentMethodPage::class)
            ->assertOk()
            ->assertSee(PaymentMethodOptions::nombre('cash'));
    }

    /**
     * El Excel dice lo mismo que la pantalla.
     *
     * Las dos consultas salen del mismo helper justamente para que no puedan
     * separarse. Si alguien vuelve a escribir la consulta dentro del
     * controlador, esta prueba deja de tener sentido y hay que sospechar.
     */
    public function test_el_excel_sale_del_mismo_calculo_que_la_pantalla(): void
    {
        $fuente = file_get_contents(
            app_path('Http/Controllers/App/ReportExportController.php')
        );

        $this->assertStringContainsString('SalesByPaymentMethod::filas($filtros)', $fuente,
            'Si el Excel arma su propia consulta, tarde o temprano dirá otro número.');
    }

    /** Y la descarga responde. */
    public function test_la_descarga_responde(): void
    {
        $factura = $this->facturaContabilizada();
        $this->pago($factura, 'cash', 4000);

        $this->get(route('reports.export.sales_by_payment_method', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]))->assertOk();
    }

    // --------------------------------------------------------- auxiliares

    /** @return array<string, mixed> */
    private function filtrosDeHoy(): array
    {
        return [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'location_id' => null,
            'created_by_user_id' => null,
        ];
    }

    /**
     * Se arma desde cero, sin copiar una factura existente.
     *
     * En la base de desarrollo las facturas de la empresa están todas
     * borradas, así que tomar «la última» como molde dejaba la prueba
     * dependiendo de datos que pueden no estar.
     */
    private function facturaContabilizada(?string $fecha = null): SaleInvoice
    {
        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $tercero = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $sede->id,
            'third_party_id' => $tercero->id,
            'prefix' => 'ZZPM',
            'number' => random_int(900000, 999999),
            'date' => $fecha ?? now()->toDateString(),
            'due_date' => $fecha ?? now()->toDateString(),
            'status' => SaleInvoice::STATUS_POSTED,
            'subtotal' => 100000,
            'tax_total' => 0,
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }

    private function pago(
        SaleInvoice $factura,
        string $metodo,
        float $monto,
        ?string $fecha = null,
        ?int $anticipoId = null,
        ?int $turnoId = null,
    ): void {
        $pago = Payment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'paymentable_type' => SaleInvoice::class,
            'paymentable_id' => $factura->id,
            'third_party_id' => $factura->third_party_id,
            'customer_advance_id' => $anticipoId,
            'cash_register_session_id' => $turnoId,
            'date' => $fecha ?? now()->toDateString(),
            'amount' => $monto,
            'payment_method' => $metodo,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('payments')->where('id', $pago->id)->delete();
    }

    private function abrirCaja(): CashRegisterSession
    {
        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->subHour(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function anticipo(?string $metodo, float $monto, ?int $turnoId = null): CustomerAdvance
    {
        $tercero = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $anticipo = CustomerAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'third_party_id' => $tercero->id,
            'cash_register_session_id' => $turnoId,
            'date' => now()->toDateString(),
            'amount' => $monto,
            'applied_amount' => 0,
            'payment_method' => $metodo,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        return $anticipo;
    }
}
