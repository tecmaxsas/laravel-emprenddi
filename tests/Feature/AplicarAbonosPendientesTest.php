<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\CustomerAdvanceService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El comando que reaplica el saldo a favor con la regla corregida.
 *
 * Hasta el 06/11/2026 los abonos se aplicaban solo a las facturas y saltaban
 * el saldo de apertura. Los clientes que quedaron con saldo a favor sin usar
 * tienen que poder corregirse sin tocarlos uno por uno.
 *
 * **Lo que más cuida esta prueba es la simulación.** Un comando que mueve
 * cartera de clientes reales y no se puede ver antes de ejecutarlo es un
 * comando que nadie debería correr.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class AplicarAbonosPendientesTest extends TestCase
{
    private Company $empresa;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->empresa);

        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZAPL'.random_int(100000, 999999),
            'name' => 'ZZAPL Cliente con saldo viejo',
            'is_customer' => true,
            'opening_balance' => 500000,
            'opening_balance_date' => now()->subYear()->toDateString(),
            'opening_balance_applied' => 0,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('customer_advances')->where('third_party_id', $this->cliente->id)->delete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    /**
     * La simulación NO escribe.
     *
     * Es la parte que hace que el comando se pueda correr sobre datos reales:
     * se mira primero, se ejecuta después.
     */
    public function test_la_simulacion_no_escribe(): void
    {
        $this->conSaldoAFavor(200000);

        $this->artisan('cartera:aplicar-abonos', [
            'company' => $this->empresa->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertEqualsWithDelta(0, (float) $this->cliente->fresh()->opening_balance_applied, 0.01,
            'En simulación no se puede haber movido nada.');
    }

    /**
     * La simulación dice exactamente lo que va a pasar.
     *
     * La primera versión calculaba aparte cuánto iría al saldo de apertura y
     * mentía: la ejecución real aplica ADEMÁS el resto a las facturas, así que
     * la columna «queda a favor» salía inflada. Alguien iba a decidir mirando
     * una tabla que no describía lo que estaba a punto de pasar.
     *
     * Ahora la simulación ejecuta de verdad y deshace, así que lo que muestra
     * ES lo que pasa.
     */
    public function test_la_simulacion_dice_lo_mismo_que_la_ejecucion(): void
    {
        $this->conSaldoAFavor(200000);

        $simulado = $this->salidaDe(['--dry-run' => true]);

        $this->assertEqualsWithDelta(0, (float) $this->cliente->fresh()->opening_balance_applied, 0.01,
            'La simulación no puede dejar rastro.');

        $ejecutado = $this->salidaDe([]);

        $this->assertSame(
            $this->cifrasDe($simulado),
            $this->cifrasDe($ejecutado),
            'Si la simulación y la ejecución no dicen lo mismo, la simulación no sirve.',
        );
    }

    /** Y sin simulación, aplica contra la deuda vieja. */
    public function test_aplica_el_saldo_a_favor_a_la_apertura(): void
    {
        $this->conSaldoAFavor(200000);

        $this->artisan('cartera:aplicar-abonos', ['company' => $this->empresa->id])
            ->assertSuccessful();

        $fresco = $this->cliente->fresh();

        $this->assertEqualsWithDelta(200000, (float) $fresco->opening_balance_applied, 0.01);
        $this->assertEqualsWithDelta(300000, $fresco->saldoDeAperturaPendiente(), 0.01);
    }

    /** Correrlo dos veces no aplica de más. */
    public function test_correrlo_dos_veces_no_duplica(): void
    {
        $this->conSaldoAFavor(200000);

        $this->artisan('cartera:aplicar-abonos', ['company' => $this->empresa->id])->assertSuccessful();
        $this->artisan('cartera:aplicar-abonos', ['company' => $this->empresa->id])->assertSuccessful();

        $this->assertEqualsWithDelta(200000, (float) $this->cliente->fresh()->opening_balance_applied, 0.01,
            'El saldo a favor ya se consumió: la segunda pasada no tiene qué aplicar.');
    }

    /** Nunca aplica más de lo que el cliente debía. */
    public function test_no_aplica_mas_de_la_deuda(): void
    {
        $this->conSaldoAFavor(800000);

        $this->artisan('cartera:aplicar-abonos', ['company' => $this->empresa->id])->assertSuccessful();

        $fresco = $this->cliente->fresh();

        $this->assertEqualsWithDelta(500000, (float) $fresco->opening_balance_applied, 0.01,
            'Debía $500.000: no puede abonársele más.');
        $this->assertEqualsWithDelta(300000, app(CustomerAdvanceService::class)->availableBalance($fresco), 0.01,
            'Y lo que sobra le queda a favor.');
    }

    /** Sin empresa no hace nada. */
    public function test_exige_identificar_la_empresa(): void
    {
        $this->artisan('cartera:aplicar-abonos')->assertFailed();
    }

    /**
     * Con un nombre ambiguo se niega y lista.
     *
     * Correrlo sobre la empresa equivocada le mueve la cartera a otro negocio.
     */
    public function test_un_nombre_ambiguo_no_elige_por_su_cuenta(): void
    {
        $coincidencias = Company::withoutGlobalScopes()->where('name', 'ilike', '%a%')->count();

        if ($coincidencias < 2) {
            $this->markTestSkipped('Se necesitan dos empresas con nombre parecido.');
        }

        $this->artisan('cartera:aplicar-abonos', ['--nombre' => 'a'])->assertFailed();
    }

    /** @param  array<string, mixed>  $opciones */
    private function salidaDe(array $opciones): string
    {
        Artisan::call('cartera:aplicar-abonos', [
            'company' => $this->empresa->id,
            ...$opciones,
        ]);

        return Artisan::output();
    }

    /** Las cifras de la fila del cliente, para comparar simulación y ejecución. */
    private function cifrasDe(string $salida): array
    {
        preg_match_all('/\$[\d.]+/', $salida, $m);

        return $m[0];
    }

    // --------------------------------------------------------- auxiliares

    private function conSaldoAFavor(float $monto): void
    {
        // Directo, sin pasar por el servicio: registrarlo con `register()` ya
        // lo aplicaría, y lo que se prueba aquí es el arrastre de los que
        // quedaron sueltos ANTES de la corrección.
        DB::table('customer_advances')->insert([
            'company_id' => $this->empresa->id,
            'third_party_id' => $this->cliente->id,
            'date' => now()->subMonths(3)->toDateString(),
            'amount' => $monto,
            'applied_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
