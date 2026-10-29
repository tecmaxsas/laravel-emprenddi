<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Repartir los permisos nuevos a las empresas que ya existen.
 *
 * Hace falta porque los roles son por empresa: cada una trabaja sobre su copia
 * de la plantilla. `RolesSeeder` mantiene las plantillas, pero las copias se
 * quedan como estaban, y el resultado es que se publica un módulo, se
 * despliega, y en las empresas viejas no funciona para nadie —ni para el
 * administrador—. Como el módulo «no sirve», la primera reacción es buscar el
 * error en el código.
 *
 * Lo que estas pruebas fijan son las DOS mitades de la regla, porque pasarse
 * de un lado es tan malo como del otro:
 *
 *   · Dar de menos → el módulo nuevo no funciona y nadie sabe por qué.
 *   · Dar de más  → se le devuelven a un rol permisos que un administrador
 *     le había quitado a propósito. Eso no se nota, y es peor.
 *
 * La regla: se da un permiso que falta **solo si nació después que el rol**.
 * Si nació después, la copia nunca pudo tenerlo y nadie pudo quitárselo.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class SyncRolePermissionsTest extends TestCase
{
    private Company $empresa;

    private Role $rol;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Company::create([
            'nit' => 'ZS'.random_int(1000000, 9999999),
            'name' => 'Empresa ZZ Sync',
            'document_type' => 'nit',
            'organization_type' => 'juridica',
            'regime_type' => 'comun',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'active' => true,
        ]);

        // Un rol «cashier» de esa empresa, creado AHORA. Los permisos que se
        // inventen después serán más nuevos que él.
        $this->rol = Role::create([
            'company_id' => $this->empresa->id,
            'name' => 'cashier',
            'guard_name' => 'web',
        ]);

        $this->limpiar[] = function () {
            DB::table('role_has_permissions')->where('role_id', $this->rol->id)->delete();
            DB::table('roles')->where('id', $this->rol->id)->delete();
            DB::table('companies')->where('id', $this->empresa->id)->delete();
        };
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $paso) {
            try {
                $paso();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    /**
     * Un permiso que nació después que el rol se reparte.
     *
     * Es el caso del módulo nuevo: nadie pudo habérselo quitado porque hasta
     * ayer no existía.
     */
    public function test_reparte_los_permisos_que_nacieron_despues_del_rol(): void
    {
        $permiso = $this->permisoDelCajero('pos.use');

        // Nace después que el rol.
        DB::table('permissions')->where('id', $permiso->id)
            ->update(['created_at' => now()->addMinute()]);

        DB::table('role_has_permissions')
            ->where('role_id', $this->rol->id)
            ->where('permission_id', $permiso->id)
            ->delete();

        $this->artisan('roles:sync-catalog')->assertSuccessful();

        $this->assertTrue(
            $this->rolTiene($permiso->id),
            'Un permiso más nuevo que el rol tiene que repartirse: es el módulo recién publicado.',
        );
    }

    /**
     * Un permiso que ya existía cuando se creó el rol NO se devuelve.
     *
     * Si el rol no lo tiene es porque alguien se lo quitó. Devolvérselo sería
     * pisar una decisión que nadie pidió revertir, y en silencio.
     */
    public function test_no_devuelve_lo_que_alguien_quito_a_proposito(): void
    {
        $permiso = $this->permisoDelCajero('pos.use');

        // Ya existía mucho antes que el rol.
        DB::table('permissions')->where('id', $permiso->id)
            ->update(['created_at' => now()->subYear()]);

        DB::table('role_has_permissions')
            ->where('role_id', $this->rol->id)
            ->where('permission_id', $permiso->id)
            ->delete();

        $this->artisan('roles:sync-catalog')->assertSuccessful();

        $this->assertFalse(
            $this->rolTiene($permiso->id),
            'Le devolvió un permiso que el administrador le había quitado.',
        );
    }

    /** Correrlo dos veces no cambia nada la segunda. */
    public function test_es_idempotente(): void
    {
        $permiso = $this->permisoDelCajero('pos.use');

        DB::table('permissions')->where('id', $permiso->id)
            ->update(['created_at' => now()->addMinute()]);

        DB::table('role_has_permissions')
            ->where('role_id', $this->rol->id)
            ->where('permission_id', $permiso->id)
            ->delete();

        $this->artisan('roles:sync-catalog')->assertSuccessful();
        $antes = DB::table('role_has_permissions')->where('role_id', $this->rol->id)->count();

        $this->artisan('roles:sync-catalog')->assertSuccessful();
        $despues = DB::table('role_has_permissions')->where('role_id', $this->rol->id)->count();

        $this->assertSame($antes, $despues);
    }

    /** `--dry-run` no escribe nada. */
    public function test_la_simulacion_no_escribe(): void
    {
        $permiso = $this->permisoDelCajero('pos.use');

        DB::table('permissions')->where('id', $permiso->id)
            ->update(['created_at' => now()->addMinute()]);

        DB::table('role_has_permissions')
            ->where('role_id', $this->rol->id)
            ->where('permission_id', $permiso->id)
            ->delete();

        $this->artisan('roles:sync-catalog', ['--dry-run' => true])->assertSuccessful();

        $this->assertFalse($this->rolTiene($permiso->id), 'La simulación escribió.');
    }

    /**
     * Un rol que el administrador inventó no se toca.
     *
     * No está en el catálogo, así que nadie sabe qué debería tener. Darle los
     * permisos de otro rol sería inventarse su alcance.
     */
    public function test_no_toca_los_roles_inventados(): void
    {
        $inventado = Role::create([
            'company_id' => $this->empresa->id,
            'name' => 'ZZ supervisor de piso',
            'guard_name' => 'web',
        ]);

        $this->limpiar[] = function () use ($inventado) {
            DB::table('role_has_permissions')->where('role_id', $inventado->id)->delete();
            DB::table('roles')->where('id', $inventado->id)->delete();
        };

        $this->artisan('roles:sync-catalog')->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('role_has_permissions')->where('role_id', $inventado->id)->count(),
            'Le inventó permisos a un rol que no está en el catálogo.',
        );
    }

    /**
     * El comando sirve aunque los permisos ya existan.
     *
     * Es el caso real y el que falló: `deploy.sh` corre `RolesSeeder` antes,
     * que ya crea las filas de permiso. La primera versión solo repartía lo
     * que ella misma acababa de crear, así que en el deploy no encontraba nada
     * «nuevo» y dejaba a las empresas sin los permisos, respondiendo que todo
     * estaba completo.
     */
    public function test_reparte_aunque_el_permiso_ya_estuviera_creado(): void
    {
        $permiso = $this->permisoDelCajero('pos.use');

        $this->assertTrue(
            DB::table('permissions')->where('id', $permiso->id)->exists(),
            'El permiso ya tiene que existir para que esta prueba signifique algo.',
        );

        DB::table('permissions')->where('id', $permiso->id)
            ->update(['created_at' => now()->addMinute()]);

        DB::table('role_has_permissions')
            ->where('role_id', $this->rol->id)
            ->where('permission_id', $permiso->id)
            ->delete();

        $this->artisan('roles:sync-catalog')->assertSuccessful();

        $this->assertTrue($this->rolTiene($permiso->id));
    }

    // --------------------------------------------------------- auxiliares

    /** Un permiso que el catálogo le asigna por defecto al rol «cashier». */
    private function permisoDelCajero(string $nombre): Permission
    {
        $permiso = Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        $original = DB::table('permissions')->where('id', $permiso->id)->value('created_at');

        // La fecha se toca para armar cada escenario: se devuelve como estaba.
        $this->limpiar[] = fn () => DB::table('permissions')
            ->where('id', $permiso->id)
            ->update(['created_at' => $original]);

        return $permiso;
    }

    private function rolTiene(int $permisoId): bool
    {
        return DB::table('role_has_permissions')
            ->where('role_id', $this->rol->id)
            ->where('permission_id', $permisoId)
            ->exists();
    }
}
