<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Escritorio;
use App\Filament\App\Widgets\DashboardOverviewWidget;
use App\Models\Company;
use App\Models\User;
use App\Services\Auth\PermissionsCatalog;
use App\Support\CurrentCompany;
use App\Support\DashboardSections;
use App\Support\PosDestination;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Quién ve las cifras del negocio en el escritorio.
 *
 * Un cajero necesita `sales.view` para buscar una factura e `inventory.view`
 * para mirar existencias: sin eso no puede trabajar. Pero con esos mismos
 * permisos el escritorio le mostraba **las ventas del mes, la cartera por
 * cobrar y lo que la empresa le debe a sus proveedores**, en una pantalla que
 * está encendida sobre el mostrador todo el día.
 *
 * Son dos cosas distintas: consultar un documento y ver el total del negocio.
 * Por eso ahora van en permisos distintos.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class EscritorioCifrasTest extends TestCase
{
    private Company $empresa;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($admin->company_id);
        app(CurrentCompany::class)->set($this->empresa);
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

    /** El cajero NO trae el permiso de las cifras. */
    public function test_el_cajero_no_ve_las_cifras_por_defecto(): void
    {
        $this->assertNotContains('dashboard.financials', PermissionsCatalog::defaultForRole('cashier'));
        $this->assertNotContains('dashboard.financials', PermissionsCatalog::defaultForRole('seller'));
    }

    /** Pero conserva lo que necesita para trabajar. */
    public function test_el_cajero_conserva_lo_que_necesita(): void
    {
        $cajero = PermissionsCatalog::defaultForRole('cashier');

        $this->assertContains('sales.view', $cajero, 'Tiene que poder buscar una factura.');
        $this->assertContains('inventory.view', $cajero, 'Y mirar existencias.');
    }

    /** El administrador, el gerente y el contador sí. */
    public function test_quien_dirige_el_negocio_si_las_ve(): void
    {
        foreach (['admin', 'manager', 'accountant'] as $rol) {
            $this->assertContains('dashboard.financials', PermissionsCatalog::defaultForRole($rol),
                "El rol {$rol} tiene que ver las cifras.");
        }
    }

    /**
     * Sin el permiso, el escritorio no carga las cifras.
     *
     * No basta con esconder las secciones: las tarjetas de arriba se arman con
     * estos datos, así que si se cargan igual, se ven igual.
     */
    public function test_sin_el_permiso_el_escritorio_no_trae_cifras(): void
    {
        $usuario = $this->usuarioConPermisos(['sales.view', 'purchases.view', 'inventory.view']);

        $this->actingAs($usuario);

        $datos = (new DashboardOverviewWidget)->getViewData();

        $this->assertNull($datos['sales'], 'Sin el permiso no puede traer las ventas del mes.');
        $this->assertNull($datos['purchases'], 'Ni lo que se le debe a proveedores.');
        $this->assertNull($datos['dueInvoices']);
    }

    /** Con el permiso, sí. */
    public function test_con_el_permiso_el_escritorio_trae_cifras(): void
    {
        $usuario = $this->usuarioConPermisos([
            'sales.view', 'purchases.view', 'inventory.view', 'dashboard.financials',
        ]);

        $this->actingAs($usuario);

        $datos = (new DashboardOverviewWidget)->getViewData();

        $this->assertNotNull($datos['sales'], 'Quien dirige el negocio tiene que verlas.');
    }

    /** Y las secciones con cifras tampoco se ofrecen sin el permiso. */
    public function test_las_secciones_con_cifras_piden_el_permiso(): void
    {
        $sinPermiso = $this->usuarioConPermisos(['sales.view', 'purchases.view']);

        foreach (['kpis', 'sales_chart', 'due_invoices', 'activity'] as $seccion) {
            $this->assertFalse(DashboardSections::userCanSee($seccion, $sinPermiso),
                "La sección «{$seccion}» muestra cifras del negocio.");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  A quien no tiene nada que ver, se le manda a trabajar */
    /* ------------------------------------------------------------------ */

    /**
     * Sin secciones disponibles y con POS, va directo al POS.
     *
     * Separar las cifras dejó al cajero entrando a una pantalla casi vacía.
     * Eso no es una mejora: es un paso de más entre abrir sesión y poder
     * cobrar.
     */
    public function test_al_que_no_tiene_nada_que_ver_lo_manda_al_pos(): void
    {
        $usuario = $this->usuarioConPermisos(['pos.use']);

        $this->actingAs($usuario);

        Livewire::test(Escritorio::class)
            ->assertRedirect(PosDestination::url());
    }

    /**
     * Pero quien sí tiene algo que ver se queda.
     *
     * La condición se mide por PERMISOS, no por el nombre del rol: un rol
     * llamado «cajero» al que alguien le habilite los indicadores debe
     * quedarse, y uno llamado «mostrador» sin nada que ver debe irse igual.
     */
    public function test_al_que_tiene_cifras_lo_deja_en_el_escritorio(): void
    {
        $usuario = $this->usuarioConPermisos(['pos.use', 'sales.view', 'dashboard.financials']);

        $this->actingAs($usuario);

        Livewire::test(Escritorio::class)
            ->assertNoRedirect();
    }

    /** Y a quien no tiene ni secciones ni POS, tampoco lo manda a ningún lado. */
    public function test_sin_pos_no_lo_manda_a_ninguna_parte(): void
    {
        $usuario = $this->usuarioConPermisos(['third_parties.view']);

        $this->actingAs($usuario);

        Livewire::test(Escritorio::class)
            ->assertNoRedirect();
    }

    // --------------------------------------------------------- auxiliares

    /** @param  list<string>  $permisos */
    private function usuarioConPermisos(array $permisos): User
    {
        $usuario = User::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'name' => 'ZZDASH Usuario',
            'email' => 'zzdash'.random_int(100000, 999999).'@pruebas.local',
            'password' => 'no-se-usa-'.random_int(1000, 9999),
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($usuario) {
            DB::table('model_has_permissions')->where('model_id', $usuario->id)
                ->where('model_type', User::class)->delete();
            DB::table('users')->where('id', $usuario->id)->delete();
        };

        foreach ($permisos as $nombre) {
            $permiso = Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

            if ($permiso->wasRecentlyCreated) {
                $this->limpiar[] = fn () => $permiso->delete();
            }

            $usuario->givePermissionTo($permiso);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $usuario->fresh();
    }
}
