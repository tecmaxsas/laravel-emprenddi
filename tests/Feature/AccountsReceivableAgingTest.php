<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Reports\AccountsReceivableAgingPage;
use App\Models\Company;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Reports\AccountsReceivableAging;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cartera por edades.
 *
 * El listado factura por factura ya existía. Este reporte responde la otra
 * pregunta, la de cobranza: a quién hay que llamar y qué tan tarde va. Un
 * cliente con diez facturas al día no es el mismo problema que uno con una sola
 * de hace cuatro meses, y en el listado plano los dos se ven igual.
 *
 * Lo que estas pruebas cuidan es que los números sean ciertos. Un reporte de
 * cartera equivocado no se nota al abrirlo: se nota cuando alguien llama a
 * cobrarle a un cliente que ya pagó, o cuando una deuda de cuatro meses aparece
 * como corriente y nadie la persigue.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class AccountsReceivableAgingTest extends TestCase
{
    private Company $company;

    private Location $sede;

    private ThirdParty $cliente;

    private ThirdParty $otroCliente;

    /** @var list<callable> */
    private array $limpiar = [];

    private string $corte;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->corte = now()->toDateString();

        $this->cliente = $this->crearCliente('ZZAGE CLIENTE UNO');
        $this->otroCliente = $this->crearCliente('ZZAGE CLIENTE DOS');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** Cada factura cae en el tramo que le corresponde por su mora. */
    public function test_cada_factura_cae_en_su_tramo(): void
    {
        $this->factura($this->cliente, saldo: 100000, venceHace: -5);   // aún no vence
        $this->factura($this->cliente, saldo: 200000, venceHace: 10);   // 1–30
        $this->factura($this->cliente, saldo: 300000, venceHace: 45);   // 31–60
        $this->factura($this->cliente, saldo: 400000, venceHace: 75);   // 61–90
        $this->factura($this->cliente, saldo: 500000, venceHace: 120);  // +90

        $fila = $this->filaDe($this->cliente);

        $this->assertEqualsWithDelta(100000, $fila['corriente'], 0.01);
        $this->assertEqualsWithDelta(200000, $fila['d1_30'], 0.01);
        $this->assertEqualsWithDelta(300000, $fila['d31_60'], 0.01);
        $this->assertEqualsWithDelta(400000, $fila['d61_90'], 0.01);
        $this->assertEqualsWithDelta(500000, $fila['d90_mas'], 0.01);
        $this->assertEqualsWithDelta(1500000, $fila['total'], 0.01);
        $this->assertSame(5, $fila['facturas']);
    }

    /**
     * Los bordes de cada tramo.
     *
     * Es donde se equivocan estos reportes: un «31 – 60» que en realidad empieza
     * en 32 deja un día sin contar, y esa factura desaparece del informe sin que
     * nadie lo note.
     */
    public function test_los_bordes_de_los_tramos(): void
    {
        $this->factura($this->cliente, saldo: 10, venceHace: 0);    // vence hoy: corriente
        $this->factura($this->cliente, saldo: 20, venceHace: 1);    // primer día de mora
        $this->factura($this->cliente, saldo: 40, venceHace: 30);   // último de 1–30
        $this->factura($this->cliente, saldo: 80, venceHace: 31);   // primero de 31–60
        $this->factura($this->cliente, saldo: 160, venceHace: 90);  // último de 61–90
        $this->factura($this->cliente, saldo: 320, venceHace: 91);  // primero de +90

        $fila = $this->filaDe($this->cliente);

        $this->assertEqualsWithDelta(10, $fila['corriente'], 0.01, 'Vencer hoy no es estar en mora.');
        $this->assertEqualsWithDelta(60, $fila['d1_30'], 0.01, 'Debe cubrir del día 1 al 30.');
        $this->assertEqualsWithDelta(80, $fila['d31_60'], 0.01);
        $this->assertEqualsWithDelta(160, $fila['d61_90'], 0.01, 'El día 90 todavía es 61–90.');
        $this->assertEqualsWithDelta(320, $fila['d90_mas'], 0.01);
    }

    /**
     * Una factura sin fecha de vencimiento es corriente, no vencida.
     *
     * No tener plazo pactado no es estar en mora. Contarla como vencida inflaría
     * el tramo más grave con facturas que nadie puede reclamar todavía, y ese es
     * el tramo que dispara las llamadas de cobranza.
     */
    public function test_una_factura_sin_vencimiento_no_esta_vencida(): void
    {
        $this->factura($this->cliente, saldo: 700000, venceHace: null);

        $fila = $this->filaDe($this->cliente);

        $this->assertEqualsWithDelta(700000, $fila['corriente'], 0.01);
        $this->assertEqualsWithDelta(0, $fila['d90_mas'], 0.01);
        $this->assertSame(0, $fila['dias_max']);
    }

    /** El saldo es lo que falta por cobrar, no el total de la factura. */
    public function test_cuenta_el_saldo_y_no_el_total(): void
    {
        $this->factura($this->cliente, saldo: 400000, venceHace: 10, pagado: 600000);

        $this->assertEqualsWithDelta(400000, $this->filaDe($this->cliente)['total'], 0.01,
            'Cobrar de nuevo lo ya pagado es la peor falla posible de este reporte.');
    }

    /** Una factura ya pagada no aparece. */
    public function test_una_factura_pagada_no_aparece(): void
    {
        $this->factura($this->cliente, saldo: 0, venceHace: 40, pagado: 500000, estadoPago: 'pagado');

        $this->assertNull($this->filaDe($this->cliente),
            'Un cliente al día no tiene por qué salir en un informe de cartera.');
    }

    /** Un borrador tampoco: todavía no es una deuda. */
    public function test_un_borrador_no_es_cartera(): void
    {
        $this->factura($this->cliente, saldo: 900000, venceHace: 50, estado: SaleInvoice::STATUS_DRAFT);

        $this->assertNull($this->filaDe($this->cliente));
    }

    /** Ni una factura borrada. */
    public function test_una_factura_borrada_no_es_cartera(): void
    {
        $factura = $this->factura($this->cliente, saldo: 800000, venceHace: 50);

        $this->assertNotNull($this->filaDe($this->cliente));

        DB::table('sale_invoices')->where('id', $factura->id)->update(['deleted_at' => now()]);

        $this->assertNull($this->filaDe($this->cliente),
            'La cartera incluiría plata que nadie va a cobrar.');
    }

    /** Cada cliente va en su propia fila. */
    public function test_cada_cliente_en_su_fila(): void
    {
        $this->factura($this->cliente, saldo: 100000, venceHace: 10);
        $this->factura($this->otroCliente, saldo: 250000, venceHace: 100);

        $this->assertEqualsWithDelta(100000, $this->filaDe($this->cliente)['total'], 0.01);
        $this->assertEqualsWithDelta(250000, $this->filaDe($this->otroCliente)['total'], 0.01);
    }

    /** La mora que se muestra es la de la factura más atrasada. */
    public function test_la_mora_es_la_de_la_factura_mas_atrasada(): void
    {
        $this->factura($this->cliente, saldo: 100000, venceHace: 5);
        $this->factura($this->cliente, saldo: 100000, venceHace: 200);

        $this->assertSame(200, $this->filaDe($this->cliente)['dias_max'],
            'Mostrar la menor haría ver al cliente mejor de lo que está.');
    }

    /** Una factura posterior al corte no cuenta. */
    public function test_el_corte_deja_fuera_lo_posterior(): void
    {
        $this->factura($this->cliente, saldo: 500000, venceHace: 10, emitidaHace: -10);

        $this->assertNull($this->filaDe($this->cliente),
            'A la fecha de corte esa factura todavía no existía.');
    }

    /** Los totales del pie cuadran con la suma de las filas. */
    public function test_los_totales_cuadran_con_las_filas(): void
    {
        $this->factura($this->cliente, saldo: 100000, venceHace: 10);
        $this->factura($this->cliente, saldo: 300000, venceHace: 100);
        $this->factura($this->otroCliente, saldo: 250000, venceHace: 45);

        $motor = app(AccountsReceivableAging::class);
        $filas = $motor->porTercero($this->company->id, $this->corte);
        $totales = $motor->totales($filas);

        $this->assertEqualsWithDelta(
            $filas->sum('total'),
            $totales['total'],
            0.01,
            'Un reporte cuyo pie no cuadra con su cuerpo no lo usa nadie.');

        $sumaTramos = collect(AccountsReceivableAging::TRAMOS)
            ->sum(fn (array $t) => $totales[$t['clave']]);

        $this->assertEqualsWithDelta($totales['total'], $sumaTramos, 0.01,
            'Los tramos tienen que repartir el total sin perder ni duplicar un peso.');
    }

    // ------------------------------------------------- pantalla y Excel

    /**
     * La pantalla se dibuja con datos reales.
     *
     * Las pruebas de arriba miden el cálculo; esta mide lo que el usuario ve. Un
     * error de tecleo en la plantilla no rompe ningún cálculo y aun así deja la
     * página en blanco.
     */
    public function test_la_pantalla_muestra_la_cartera(): void
    {
        $this->factura($this->cliente, saldo: 1250000, venceHace: 100);

        Livewire::test(AccountsReceivableAgingPage::class)
            ->assertOk()
            ->assertSee('ZZAGE CLIENTE UNO')
            ->assertSee('1.250.000')
            ->assertSee('Más de 90');
    }

    /** Sin cartera, lo dice en vez de mostrar una tabla vacía. */
    public function test_sin_cartera_la_pantalla_lo_dice(): void
    {
        Livewire::test(AccountsReceivableAgingPage::class)
            ->assertOk()
            ->assertSee('No hay cartera pendiente');
    }

    /** El Excel se descarga y no viene vacío. */
    public function test_el_excel_se_descarga(): void
    {
        $this->factura($this->cliente, saldo: 640000, venceHace: 45);

        $respuesta = $this->get(route('reports.export.accounts_receivable_aging', [
            'as_of' => $this->corte,
        ]));

        $respuesta->assertOk();
        $respuesta->assertDownload("cartera-edades-{$this->corte}.xlsx");

        $this->assertGreaterThan(0, strlen($respuesta->streamedContent()),
            'El archivo llegó vacío.');
    }

    /** Sin permiso no se descarga. */
    public function test_el_excel_exige_permiso(): void
    {
        $sinPermiso = User::query()
            ->where('company_id', $this->company->id)
            ->get()
            ->first(fn (User $u) => ! $u->can('reports.accounts_receivable'));

        if (! $sinPermiso) {
            $this->markTestSkipped('Todos los usuarios de la empresa de desarrollo tienen el permiso.');
        }

        $this->actingAs($sinPermiso)
            ->get(route('reports.export.accounts_receivable_aging', ['as_of' => $this->corte]))
            ->assertForbidden();
    }

    // --------------------------------------------------------- auxiliares

    /** @return array<string, mixed>|null */
    private function filaDe(ThirdParty $cliente): ?array
    {
        return app(AccountsReceivableAging::class)
            ->porTercero($this->company->id, $this->corte)
            ->firstWhere('third_party_id', $cliente->id);
    }

    private function factura(
        ThirdParty $cliente,
        float $saldo,
        ?int $venceHace,
        float $pagado = 0,
        string $estado = SaleInvoice::STATUS_POSTED,
        string $estadoPago = 'pendiente',
        int $emitidaHace = 200,
    ): SaleInvoice {
        $total = $saldo + $pagado;

        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $cliente->id,
            'prefix' => 'ZZAGE',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'electronic',
            'date' => now()->subDays($emitidaHace)->toDateString(),
            'due_date' => $venceHace === null ? null : now()->subDays($venceHace)->toDateString(),
            'currency' => 'COP',
            'status' => $estado,
            'payment_status' => $estadoPago,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
            'paid_amount' => $pagado,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }

    private function crearCliente(string $nombre): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => $nombre,
            'phone' => '3001234567',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($cliente->id)->forceDelete();

        return $cliente;
    }
}
