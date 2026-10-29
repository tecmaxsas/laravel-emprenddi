<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Services\Auth\PermissionsCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte a los roles que ya existen los permisos que nacieron después que
 * ellos.
 *
 * Hace falta porque los roles son por empresa: cada una trabaja sobre su
 * propia copia de la plantilla, para que editarle un permiso al cajero de una
 * no se lo quite a los cajeros de las demás. `RolesSeeder` mantiene las
 * plantillas, pero las copias de las empresas quedan como estaban.
 *
 * El efecto es el que nadie espera: se publica un módulo nuevo, se despliega,
 * y en las empresas que ya existían no funciona para nadie —ni para el
 * administrador— porque sus roles no tienen los permisos nuevos. Y como el
 * módulo «no sirve», la primera reacción es buscar el error en el código.
 *
 * LA REGLA QUE HACE QUE ESTO SEA SEGURO:
 *
 *   se le da a un rol un permiso que le falta **solo si ese permiso se creó
 *   después que el rol**.
 *
 * Si el permiso nació después, la copia nunca pudo haberlo tenido y por tanto
 * nadie pudo habérselo quitado: dárselo no pisa ninguna decisión de nadie. Si
 * nació antes y el rol no lo tiene, es porque alguien se lo quitó a propósito,
 * y ahí no se toca. Repartir «todo lo que le falta» devolvería justamente eso.
 *
 * La primera versión usaba otra regla —solo los permisos que esta corrida
 * acababa de crear— y **no servía para nada en el único momento en que se
 * necesita**: `deploy.sh` corre `RolesSeeder` antes, que ya crea las filas de
 * permiso, así que al llegar este comando no había nada «nuevo» y respondía
 * «el catálogo ya está completo» dejando a las empresas sin los permisos.
 *
 *   php artisan roles:sync-catalog --dry-run
 *   php artisan roles:sync-catalog
 */
class SyncRolePermissions extends Command
{
    protected $signature = 'roles:sync-catalog
        {--dry-run : Muestra qué haría, sin escribir nada}
        {--prefijo= : Limita a los permisos que empiecen así (ej. hotel.)}';

    protected $description = 'Reparte a los roles existentes los permisos que nacieron después que ellos';

    public function handle(): int
    {
        $simulacion = (bool) $this->option('dry-run');
        $prefijo = (string) $this->option('prefijo');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $creados = $this->crearPermisosQueFaltan($simulacion);

        if ($creados !== []) {
            $this->info(($simulacion ? 'Se crearían ' : '✓ Creados ').count($creados).' permiso(s) que no existían:');

            foreach ($creados as $nombre) {
                $this->line('    '.$nombre);
            }

            $this->newLine();
        }

        /** @var array<string, \stdClass> $permisos */
        $permisos = DB::table('permissions')
            ->get(['id', 'name', 'created_at'])
            ->keyBy('name')
            ->all();

        // Quién tiene qué, de una sola consulta: con cientos de roles,
        // preguntarlo uno por uno son cientos de viajes a la base.
        $asignados = DB::table('role_has_permissions')
            ->get(['role_id', 'permission_id'])
            ->groupBy('role_id')
            ->map(fn ($filas) => $filas->pluck('permission_id')->all())
            ->all();

        $roles = Role::query()->orderBy('company_id')->orderBy('name')->get();

        $porInsertar = [];
        $tocados = 0;
        $saltadosPorFecha = 0;

        foreach ($roles as $rol) {
            // Un rol que el administrador creó a mano no está en el catálogo:
            // `defaultForRole` devuelve vacío y no se le toca nada. Correcto:
            // nadie sabe qué debería tener un rol inventado.
            $corresponden = PermissionsCatalog::defaultForRole($rol->name);

            if ($corresponden === []) {
                continue;
            }

            $tiene = $asignados[$rol->id] ?? [];
            $leFaltan = [];

            foreach ($corresponden as $nombre) {
                if ($prefijo !== '' && ! str_starts_with($nombre, $prefijo)) {
                    continue;
                }

                $permiso = $permisos[$nombre] ?? null;

                if (! $permiso || in_array($permiso->id, $tiene, true)) {
                    continue;
                }

                if (! $this->nacioDespues($permiso, $rol)) {
                    // Le falta, pero ya existía cuando se creó el rol: se lo
                    // quitaron a propósito.
                    $saltadosPorFecha++;

                    continue;
                }

                $leFaltan[] = $permiso->id;
            }

            if ($leFaltan === []) {
                continue;
            }

            $tocados++;

            $etiqueta = $rol->company_id
                ? "empresa #{$rol->company_id} · {$rol->name}"
                : "plantilla · {$rol->name}";

            $this->line(sprintf('  %-34s +%d', $etiqueta, count($leFaltan)));

            foreach ($leFaltan as $permisoId) {
                $porInsertar[] = ['role_id' => $rol->id, 'permission_id' => $permisoId];
            }
        }

        if ($porInsertar === []) {
            $this->info('✓ No hay nada que repartir: todos los roles tienen los permisos que les corresponden.');

            if ($saltadosPorFecha > 0) {
                $this->comment(
                    "  ({$saltadosPorFecha} asignación(es) omitida(s) porque el permiso ya existía cuando se "
                    .'creó el rol: se las quitaron a propósito y no se devuelven.)'
                );
            }

            return self::SUCCESS;
        }

        if (! $simulacion) {
            // `insertOrIgnore` en bloque y no `givePermissionTo`: son cientos
            // de roles y cargar los modelos de Spatie para cada uno es mucha
            // máquina para insertar una fila en la tabla pivote.
            foreach (array_chunk($porInsertar, 500) as $lote) {
                DB::table('role_has_permissions')->insertOrIgnore($lote);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $this->newLine();
        $this->info(sprintf(
            $simulacion
                ? 'Simulación: se tocarían %d rol(es) con %d asignación(es).'
                : '✓ %d rol(es) actualizados con %d asignación(es).',
            $tocados,
            count($porInsertar),
        ));

        if ($saltadosPorFecha > 0) {
            $this->comment(
                "  {$saltadosPorFecha} asignación(es) se omitieron porque el permiso ya existía cuando se "
                .'creó el rol: se las quitaron a propósito y no se devuelven.'
            );
        }

        if ($simulacion) {
            $this->comment('  Nada se escribió. Corre el comando sin --dry-run para aplicarlo.');
        }

        return self::SUCCESS;
    }

    /**
     * ¿El permiso nació después que el rol?
     *
     * Sin fecha en alguno de los dos no se puede afirmar, y ante la duda no se
     * toca: devolver un permiso que alguien quitó es peor que dejar uno sin
     * repartir, porque lo segundo se nota y se arregla desde la pantalla de
     * roles, y lo primero no se nota.
     */
    private function nacioDespues(object $permiso, Role $rol): bool
    {
        if (! $permiso->created_at || ! $rol->created_at) {
            return false;
        }

        return $rol->created_at->lt($permiso->created_at);
    }

    /**
     * Crea las filas de permiso que el catálogo tiene y la base no.
     *
     * Normalmente no habrá ninguna —`RolesSeeder` corre antes en el deploy—,
     * pero el comando tiene que servir también corrido solo.
     *
     * @return list<string> los que faltaban
     */
    private function crearPermisosQueFaltan(bool $simulacion): array
    {
        $existentes = DB::table('permissions')->pluck('name')->all();
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
