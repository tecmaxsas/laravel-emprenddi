<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Reports\SalesByPaymentMethodPage;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
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
     * Un abono que vino de un anticipo muestra con qué pagó el cliente.
     *
     * Al aplicar un anticipo a una factura, el pago se guarda con el método
     * `advance`. Eso describe el mecanismo contable —se cruza un pasivo contra
     * la cartera— pero no responde la pregunta del reporte: el cliente no pagó
     * «con un anticipo», pagó en efectivo o por transferencia el día que
     * entregó esa plata.
     *
     * En producción esa fila «Anticipo del cliente» se tragó el 94% del
     * recaudo y escondió justamente el dato que se buscaba.
     */
    public function test_un_anticipo_muestra_el_metodo_con_que_pago_el_cliente(): void
    {
        $factura = $this->facturaContabilizada();
        $anticipo = $this->anticipo('bank_transfer', 50000);

        $this->pago($factura, 'advance', 50000, null, $anticipo->id);

        $filas = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->keyBy('codigo');

        $this->assertArrayHasKey('bank_transfer', $filas->all(),
            'El cliente pagó por transferencia; «anticipo» es el mecanismo, no el medio.');
        $this->assertSame(50000.0, $filas['bank_transfer']['total']);
        $this->assertArrayNotHasKey('advance', $filas->all());
    }

    /** Un pago marcado como anticipo pero sin anticipo detrás no se inventa nada. */
    public function test_sin_anticipo_detras_se_queda_como_estaba(): void
    {
        $factura = $this->facturaContabilizada();

        $this->pago($factura, 'advance', 30000);

        $filas = collect(SalesByPaymentMethod::filas($this->filtrosDeHoy()))->keyBy('codigo');

        $this->assertSame(30000.0, $filas['advance']['total'],
            'Sin el anticipo no hay de dónde sacar el método real: inventarlo sería peor.');
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
    ): void {
        $pago = Payment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'paymentable_type' => SaleInvoice::class,
            'paymentable_id' => $factura->id,
            'third_party_id' => $factura->third_party_id,
            'customer_advance_id' => $anticipoId,
            'date' => $fecha ?? now()->toDateString(),
            'amount' => $monto,
            'payment_method' => $metodo,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('payments')->where('id', $pago->id)->delete();
    }

    private function anticipo(string $metodo, float $monto): CustomerAdvance
    {
        $tercero = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $anticipo = CustomerAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'third_party_id' => $tercero->id,
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
