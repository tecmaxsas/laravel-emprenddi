<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El arqueo de caja, impreso.
 *
 * El cierre se cuadra con el cajero delante y termina en una cifra que
 * alguien firma. Hasta ahora esa cifra vivía solo en la pantalla: para dejar
 * constancia había que copiarla a mano a un cuaderno, y lo que se copia a
 * mano es lo que después nadie puede verificar.
 *
 * **El desglose por método de pago es el punto.** Un arqueo que solo dice
 * «esperado $1.200.000» no sirve para cuadrar: la plata del cajón es solo el
 * efectivo, y el resto se confronta contra el datáfono y el extracto. Sin el
 * desglose, la diferencia aparece pero no se sabe de dónde sale.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ArqueoImprimibleTest extends TestCase
{
    private Company $empresa;

    private User $usuario;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->usuario->company_id);
        $this->actingAs($this->usuario);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->daPermisoDeCerrarCaja();
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

    /** El tiquete abre y trae lo esencial del turno. */
    public function test_el_arqueo_se_imprime(): void
    {
        $turno = $this->turnoCerrado(base: 100000, contado: 250000);

        $this->get(route('cash.session.print', ['session' => $turno->id]))
            ->assertOk()
            ->assertSee('ARQUEO DE CAJA')
            ->assertSee('EFECTIVO ESPERADO')
            ->assertSee('EFECTIVO CONTADO');
    }

    /**
     * Y trae el desglose por método de pago.
     *
     * Es lo que convierte el papel en algo con lo que se puede cuadrar.
     */
    public function test_trae_cada_metodo_de_pago_con_su_total(): void
    {
        $turno = $this->turnoCerrado(base: 0, contado: 0);

        $this->get(route('cash.session.print', ['session' => $turno->id]))
            ->assertOk()
            ->assertSee('COBRADO POR MÉTODO', false)
            ->assertSee('TOTAL COBRADO');
    }

    /** Un turno abierto no inventa un conteo que todavía no existe. */
    public function test_un_turno_abierto_no_muestra_conteo(): void
    {
        $turno = $this->turnoAbierto(base: 50000);

        $this->get(route('cash.session.print', ['session' => $turno->id]))
            ->assertOk()
            ->assertSee('EN CURSO')
            ->assertDontSee('EFECTIVO CONTADO',
                'Mostrarlo en cero haría creer que la caja cuadró.');
    }

    /** No se imprime el arqueo de otra empresa. */
    public function test_no_se_ve_el_arqueo_de_otra_empresa(): void
    {
        $otra = Company::query()->where('id', '!=', $this->empresa->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Se necesita una segunda empresa.');
        }

        // Se crea uno a propósito en vez de buscar alguno existente: una
        // prueba de aislamiento entre empresas que se salta cuando no hay
        // datos es una prueba que no protege nada el día que importa.
        $sedeAjena = Location::withoutGlobalScopes()
            ->where('company_id', $otra->id)->orderBy('id')->first();

        if (! $sedeAjena) {
            $this->markTestSkipped('La otra empresa no tiene sedes.');
        }

        $ajeno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $otra->id,
            'location_id' => $sedeAjena->id,
            'cashier_user_id' => $this->usuario->id,
            'status' => 'closed',
            'opened_at' => now()->subHours(3),
            'closed_at' => now(),
            'opening_amount' => 0,
            'closing_counted' => 0,
            'closing_expected' => 0,
            'closing_difference' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $ajeno->id)->delete();

        $this->get(route('cash.session.print', ['session' => $ajeno->id]))
            ->assertNotFound();
    }

    /** Sin permiso de cerrar caja, no se imprime. */
    public function test_exige_el_permiso_de_cerrar_caja(): void
    {
        $turno = $this->turnoCerrado(base: 0, contado: 0);

        $this->usuario->revokePermissionTo('pos.cash_close');
        $roles = DB::table('model_has_roles')
            ->where('model_type', User::class)->where('model_id', $this->usuario->id)->pluck('role_id');

        DB::table('model_has_roles')->where('model_type', User::class)
            ->where('model_id', $this->usuario->id)->delete();

        $this->limpiar[] = function () use ($roles) {
            foreach ($roles as $id) {
                DB::table('model_has_roles')->insert([
                    'role_id' => $id, 'model_type' => User::class, 'model_id' => $this->usuario->id,
                ]);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->usuario->unsetRelation('roles')->unsetRelation('permissions');

        $this->get(route('cash.session.print', ['session' => $turno->id]))
            ->assertForbidden();
    }

    // --------------------------------------------------------- auxiliares

    private function daPermisoDeCerrarCaja(): void
    {
        $permiso = Permission::firstOrCreate(['name' => 'pos.cash_close', 'guard_name' => 'web']);

        if ($permiso->wasRecentlyCreated) {
            $this->limpiar[] = fn () => $permiso->delete();
        }

        if (! $this->usuario->hasDirectPermission($permiso)) {
            $this->usuario->givePermissionTo($permiso);
            $this->limpiar[] = fn () => $this->usuario->revokePermissionTo($permiso);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->usuario->unsetRelation('permissions')->unsetRelation('roles');
    }

    private function turnoAbierto(float $base): CashRegisterSession
    {
        return $this->turno('open', $base, null);
    }

    private function turnoCerrado(float $base, float $contado): CashRegisterSession
    {
        return $this->turno('closed', $base, $contado);
    }

    private function turno(string $estado, float $base, ?float $contado): CashRegisterSession
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->usuario->id,
            'status' => $estado,
            'opened_at' => now()->subHours(6),
            'closed_at' => $estado === 'closed' ? now() : null,
            'opening_amount' => $base,
            'closing_counted' => $contado,
            'closing_expected' => $contado,
            'closing_difference' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }
}
