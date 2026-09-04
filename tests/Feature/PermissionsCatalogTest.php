<?php

namespace Tests\Feature;

use App\Services\Auth\PermissionsCatalog;
use Tests\TestCase;

/**
 * El catálogo de permisos es lo único que la pantalla de roles muestra.
 *
 * Un permiso que un recurso exige pero que no está en el catálogo no se puede
 * otorgar: la sección no aparece y el módulo queda inaccesible.
 *
 * OJO: eso NO es lo que le pasó a Gastos. Gastos pedía `purchases.view`, que
 * sí está en el catálogo, así que ninguna comprobación mecánica lo habría
 * detectado — el problema era que un módulo usara los permisos de otro, y eso
 * es criterio, no algo que se pueda verificar solo. El test de más abajo fija
 * el arreglo concreto; el primero cubre un fallo distinto y también real.
 */
class PermissionsCatalogTest extends TestCase
{
    public function test_los_permisos_que_exigen_los_recursos_estan_en_el_catalogo(): void
    {
        $catalogo = PermissionsCatalog::all();
        $huerfanos = [];

        foreach ($this->recursos() as $archivo) {
            $codigo = file_get_contents($archivo);

            preg_match_all(
                "/(?:viewPermission|managePermission)\(\)\s*:\s*string\s*\{\s*return\s*'([^']+)'/",
                $codigo,
                $coincidencias,
            );

            foreach ($coincidencias[1] as $permiso) {
                if (! in_array($permiso, $catalogo, true)) {
                    $huerfanos[] = basename($archivo).' exige '.$permiso;
                }
            }
        }

        $this->assertSame([], $huerfanos,
            "Permisos que ningún rol puede otorgar porque no están en el catálogo:\n"
            .implode("\n", $huerfanos));
    }

    /** Gastos tiene los suyos: no vuelve a colgar de compras. */
    public function test_gastos_tiene_permisos_propios(): void
    {
        $grupos = PermissionsCatalog::groups();

        $this->assertArrayHasKey('Gastos', $grupos);

        foreach (['expenses.view', 'expenses.create', 'expenses.post', 'expenses.cancel'] as $permiso) {
            $this->assertArrayHasKey($permiso, $grupos['Gastos']);
        }
    }

    /** Ningún permiso puede estar en dos grupos: la pantalla lo duplicaría. */
    public function test_ningun_permiso_esta_repetido_entre_grupos(): void
    {
        $vistos = [];
        $repetidos = [];

        foreach (PermissionsCatalog::groups() as $grupo => $permisos) {
            foreach (array_keys($permisos) as $permiso) {
                if (isset($vistos[$permiso])) {
                    $repetidos[] = $permiso.' está en "'.$vistos[$permiso].'" y en "'.$grupo.'"';
                }
                $vistos[$permiso] = $grupo;
            }
        }

        $this->assertSame([], $repetidos, implode("\n", $repetidos));
    }

    /** Todo permiso default de un rol tiene que existir en el catálogo. */
    public function test_los_permisos_por_defecto_de_cada_rol_existen(): void
    {
        $catalogo = PermissionsCatalog::all();
        $invalidos = [];

        foreach (['admin', 'manager', 'accountant', 'cashier', 'seller', 'accountant_external'] as $rol) {
            foreach (PermissionsCatalog::defaultForRole($rol) as $permiso) {
                if (! in_array($permiso, $catalogo, true)) {
                    $invalidos[] = $rol.' → '.$permiso;
                }
            }
        }

        $this->assertSame([], $invalidos,
            "Permisos por defecto que no existen en el catálogo:\n".implode("\n", $invalidos));
    }

    /** @return list<string> */
    private function recursos(): array
    {
        $directorio = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament'))
        );

        $archivos = [];

        foreach ($directorio as $archivo) {
            if ($archivo->isFile() && str_ends_with($archivo->getFilename(), '.php')) {
                $archivos[] = $archivo->getPathname();
            }
        }

        return $archivos;
    }
}
