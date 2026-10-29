<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Services\Auth\PermissionsCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos NUEVOS del catálogo a los roles que ya existen.
 *
 * Hace falta porque los roles son por empresa: cada una trabaja sobre su
 * propia copia de la plantilla, para que editarle un permiso al cajero de una
 * no se lo quite a los cajeros de las demás. RolesSeeder mantiene las
 * plantillas, pero las copias de las empresas quedan como estaban.
 *
 * El efecto es el que nadie espera: se publica un módulo nuevo, se despliega,
 * y en las empresas que ya existían no funciona para nadie —ni para el
 * administrador— porque sus roles no tienen los permisos nuevos. Y como el
 * módulo «no sirve», la primera reacción es buscar el error en el código.
 *
 * LA REGLA QUE HACE QUE ESTO SEA SEGURO: solo se reparten los permisos que
 * **esta corrida acaba de crear**. Un administrador no puede haberle quitado a
 * su cajero un permiso que hace un minuto no existía, así que agregarlos no
 * pisa ninguna decisión suya. Repartir «todos los permisos que le faltan al
 * rol» sí lo haría: devolvería todo lo que alguien quitó a propósito.
 *
 *   php artisan roles:sync-catalog
 *   php artisan roles:sync-catalog --dry-run
 */
class SyncRolePermissions extends Command
{
    protected $signature = 'roles:sync-catalog
        {--dry-run : Muestra qué haría, sin escribir nada}';

    protected $description = 'Reparte los permisos nuevos del catálogo a los roles de todas las empresas';

    public function handle(): int
    {
        $simulacion = (bool) $this->option('dry-run');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $nuevos = $this->crearPermisosQueFaltan($simulacion);

        if ($nuevos === []) {
            $this->info('✓ El catálogo ya está completo: no hay permisos nuevos que repartir.');

            return self::SUCCESS;
        }

        $this->info(($simulacion ? 'Se crearían ' : '✓ Creados ').count($nuevos).' permiso(s) nuevo(s):');

        foreach ($nuevos as $nombre) {
            $this->line('    '.$nombre);
        }

        $this->newLine();

        $ids = $simulacion
            ? []
            : Permission::query()->whereIn('name', $nuevos)->pluck('id', 'name')->all();

        $roles = Role::query()->orderBy('company_id')->orderBy('name')->get();
        $tocados = 0;
        $asignaciones = 0;

        foreach ($roles as $rol) {
            // Un rol que el administrador creó a mano no está en el catálogo:
            // `defaultForRole` devuelve vacío y no se le toca nada. Correcto:
            // nadie sabe qué debería tener un rol inventado.
            $leCorresponden = array_values(array_intersect(
                PermissionsCatalog::defaultForRole($rol->name),
                $nuevos,
            ));

            if ($leCorresponden === []) {
                continue;
            }

            $tocados++;
            $asignaciones += count($leCorresponden);

            $etiqueta = $rol->company_id
                ? "empresa #{$rol->company_id} · {$rol->name}"
                : "plantilla · {$rol->name}";

            $this->line(sprintf('  %s → +%d', $etiqueta, count($leCorresponden)));

            if ($simulacion) {
                continue;
            }

            $filas = array_map(
                fn (string $nombre) => ['role_id' => $rol->id, 'permission_id' => $ids[$nombre]],
                $leCorresponden,
            );

            // `insertOrIgnore` y no `givePermissionTo`: son cientos de roles y
            // cargar los modelos de Spatie para cada uno es mucha máquina para
            // insertar una fila en la tabla pivote.
            DB::table('role_has_permissions')->insertOrIgnore($filas);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info(sprintf(
            $simulacion
                ? 'Simulación: se tocarían %d rol(es) con %d asignación(es).'
                : '✓ %d rol(es) actualizados con %d asignación(es).',
            $tocados,
            $asignaciones,
        ));

        if ($simulacion) {
            $this->comment('Nada se escribió. Corre el comando sin --dry-run para aplicarlo.');
        }

        return self::SUCCESS;
    }

    /**
     * Crea las filas de permiso que el catálogo tiene y la base no.
     *
     * @return list<string> los que faltaban
     */
    private function crearPermisosQueFaltan(bool $simulacion): array
    {
        $existentes = Permission::query()->pluck('name')->all();
        $faltantes = array_values(array_diff(PermissionsCatalog::all(), $existentes));

        if ($simulacion || $faltantes === []) {
            return $faltantes;
        }

        foreach ($faltantes as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }

        return $faltantes;
    }
}
