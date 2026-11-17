<?php

namespace App\Services\Maintenance;

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Borra una empresa y TODO lo suyo, sin vuelta atrás.
 *
 * Distinto de CompanyDataReset, que limpia los movimientos y deja la
 * empresa viva y configurada. Esto es para la empresa que no debería
 * existir: el registro de prueba que nadie terminó de montar, la demo
 * vieja, el alta duplicada.
 *
 * ---------------------------------------------------------------------
 * POR QUÉ NO BASTA CON BORRAR LA FILA DE `companies`
 * ---------------------------------------------------------------------
 *
 * Casi todas las llaves foráneas hacia `companies` son CASCADE, así que la
 * tentación es `DELETE FROM companies` y que la base haga el resto. Con una
 * empresa vacía funciona. Con una que tenga contabilidad, no:
 *
 *   ERROR: update or delete on table "accounts" violates foreign key
 *   constraint "journal_entry_lines_account_id_foreign"
 *
 * Porque entre las tablas de la empresa hay RESTRICT —los renglones de un
 * asiento apuntan a la cuenta contable— y Postgres resuelve la cascada en
 * el orden que quiere. Si le toca `accounts` antes que `journal_entries`,
 * aborta. Depender de ese orden es depender de suerte.
 *
 * ---------------------------------------------------------------------
 * POR QUÉ NO HAY UNA LISTA DE TABLAS
 * ---------------------------------------------------------------------
 *
 * La solución obvia es escribir las tablas en el orden correcto. Ya existe
 * una así en este proyecto, en CompanyDataReset, y cuenta la historia
 * sola: enumera 46 tablas de las 112 que tienen `company_id`, y no conoce
 * ninguna de parqueadero, hotel ni billares. Nadie se acordó de volver a
 * tocarla al publicar un módulo nuevo, y así es como un borrado deja mitad
 * de la empresa en la base.
 *
 * Aquí las tablas se preguntan al esquema en cada corrida: cualquier módulo
 * que se publique mañana queda cubierto sin que nadie tenga que acordarse.
 *
 * El orden tampoco se calcula: se borra a tanteo, en pasadas. En cada
 * pasada se intenta vaciar cada tabla que queda; la que falla por una llave
 * foránea se deja para la siguiente, cuando ya se habrá ido lo que la
 * sujetaba. Es O(n²) en sentencias y da igual: esto se corre un puñado de
 * veces al año y cada sentencia es trivial.
 *
 * Si una pasada completa no logra borrar nada y todavía quedan tablas, se
 * levanta la mano con los nombres: algo de FUERA de la empresa está
 * apuntando hacia adentro, y eso lo tiene que mirar una persona. Como todo
 * va en una sola transacción, ahí no se perdió nada.
 */
class CompanyEraser
{
    /** Tope de pasadas. Con progreso real nunca se llega; es contra un bucle infinito. */
    protected const MAX_PASADAS = 40;

    /**
     * Qué hay dentro de la empresa, tabla por tabla.
     *
     * Sirve para que quien va a borrar VEA lo que va a destruir. Es la
     * protección que de verdad funciona: un «412 facturas de venta» frena
     * la mano mucho mejor que un «¿está seguro?».
     *
     * @return array<string, int> tabla => filas, solo las que tienen algo
     */
    public function resumen(Company $empresa): array
    {
        $conteos = [];

        foreach ($this->tablasDeLaEmpresa() as $tabla) {
            $filas = (int) DB::table($tabla)->where('company_id', $empresa->id)->count();

            if ($filas > 0) {
                $conteos[$tabla] = $filas;
            }
        }

        $usuarios = (int) DB::table('users')->where('company_id', $empresa->id)->count();

        if ($usuarios > 0) {
            $conteos['users'] = $usuarios;
        }

        arsort($conteos);

        return $conteos;
    }

    /**
     * Las cifras que le importan a quien decide, con nombre de persona.
     *
     * @return array<string, int>
     */
    public function loGrave(Company $empresa): array
    {
        $cuenta = fn (string $tabla) => $this->existe($tabla)
            ? (int) DB::table($tabla)->where('company_id', $empresa->id)->count()
            : 0;

        return array_filter([
            'Facturas de venta' => $cuenta('sale_invoices'),
            'Facturas de compra' => $cuenta('purchase_invoices'),
            'Asientos contables' => $cuenta('journal_entries'),
            'Pagos registrados' => $cuenta('payments'),
            'Productos' => $cuenta('products'),
            'Terceros' => $cuenta('third_parties'),
            'Empleados de nómina' => $cuenta('employees'),
            'Usuarios' => (int) DB::table('users')->where('company_id', $empresa->id)->count(),
        ]);
    }

    /**
     * Borra la empresa entera. No se puede deshacer.
     *
     * @return array<string, int> tabla => filas borradas
     *
     * @throws RuntimeException si algo queda sujeto desde fuera (no borra nada)
     */
    public function borrar(Company $empresa): array
    {
        $id = (int) $empresa->id;
        $nombre = (string) $empresa->name;
        $nit = (string) $empresa->nit;

        // Queda escrito ANTES de borrar: el registro de auditoría de la
        // empresa vive dentro de la empresa y se va con ella, así que si no
        // se anota aquí no queda rastro en ninguna parte.
        Log::warning('Borrado total de empresa: inicio', [
            'company_id' => $id,
            'nit' => $nit,
            'name' => $nombre,
            'por_usuario' => auth()->id(),
            'resumen' => $this->loGrave($empresa),
        ]);

        $borradas = DB::transaction(function () use ($empresa, $id) {
            $borradas = [];
            $pendientes = $this->tablasDeLaEmpresa();
            $pasada = 0;

            while ($pendientes !== [] && $pasada++ < self::MAX_PASADAS) {
                $quedan = [];
                $huboProgreso = false;

                foreach ($pendientes as $tabla) {
                    try {
                        // Transacción anidada = SAVEPOINT. Sin esto, la
                        // primera violación de llave foránea aborta la
                        // transacción entera y ya no se puede seguir
                        // intentando: en Postgres, una sentencia fallida
                        // deja la transacción envenenada.
                        $filas = DB::transaction(
                            fn () => DB::table($tabla)->where('company_id', $id)->delete()
                        );

                        if ($filas > 0) {
                            $borradas[$tabla] = ($borradas[$tabla] ?? 0) + $filas;
                        }

                        $huboProgreso = true;
                    } catch (QueryException) {
                        // Algo todavía la sujeta. Vuelve a la cola: en la
                        // próxima pasada ya se habrá ido.
                        $quedan[] = $tabla;
                    }
                }

                if (! $huboProgreso && $quedan !== []) {
                    throw new RuntimeException(
                        'No se pudo vaciar la empresa: estas tablas siguen sujetas por algo '
                        .'de fuera de la empresa, así que no se borró nada. Revísalas a mano: '
                        .implode(', ', $quedan)
                    );
                }

                $pendientes = $quedan;
            }

            if ($pendientes !== []) {
                throw new RuntimeException(
                    'El borrado dio demasiadas vueltas sin terminar. No se borró nada. '
                    .'Quedaron: '.implode(', ', $pendientes)
                );
            }

            // Los usuarios, DESPUÉS de vaciar las tablas y no antes.
            // `cash_register_sessions.cashier_user_id` apunta a `users` con
            // RESTRICT: mientras quede un turno de caja de la empresa, el
            // cajero no se deja borrar. Vaciadas las tablas, ya nada lo
            // sujeta. (Lo encontró una prueba, no el razonamiento.)
            $borradas = array_merge($borradas, $this->borraLosUsuarios($id));

            // La empresa, de última. Por SQL directo porque el modelo usa
            // SoftDeletes y aquí no queremos una fila marcada como borrada:
            // queremos que no quede.
            DB::table('companies')->where('id', $id)->delete();
            $borradas['companies'] = 1;

            return $borradas;
        });

        $this->borraElLogo($empresa);

        Log::warning('Borrado total de empresa: terminado', [
            'company_id' => $id,
            'nit' => $nit,
            'name' => $nombre,
            'filas_borradas' => array_sum($borradas),
        ]);

        return $borradas;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Las tablas de la empresa, preguntadas al esquema.
     *
     * `users` no entra aquí: su llave foránea es SET NULL, así que un
     * borrado la dejaría viva con la empresa en blanco —un usuario fantasma
     * que todavía puede intentar entrar—. Se borra aparte y a propósito.
     *
     * @return list<string>
     */
    protected function tablasDeLaEmpresa(): array
    {
        $filas = DB::select("
            SELECT DISTINCT c.table_name
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.column_name = 'company_id'
              AND c.table_schema = 'public'
              AND t.table_type = 'BASE TABLE'
              AND c.table_name NOT IN ('companies', 'users')
            ORDER BY c.table_name
        ");

        return array_map(fn ($f) => $f->table_name, $filas);
    }

    /**
     * Los usuarios y lo que cuelga de ellos.
     *
     * El pivote de roles no tiene llave foránea hacia `users` —Spatie lo
     * resuelve por morph— así que borrar el usuario deja la fila del pivote
     * apuntando a un id que ya no existe. Hay que quitarla a mano.
     *
     * @return array<string, int>
     */
    protected function borraLosUsuarios(int $companyId): array
    {
        $usuarios = DB::table('users')->where('company_id', $companyId)->pluck('id');

        if ($usuarios->isEmpty()) {
            return [];
        }

        $pivotes = DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->whereIn('model_id', $usuarios)
            ->delete();

        $permisos = $this->existe('model_has_permissions')
            ? DB::table('model_has_permissions')
                ->where('model_type', \App\Models\User::class)
                ->whereIn('model_id', $usuarios)
                ->delete()
            : 0;

        $borrados = DB::table('users')->whereIn('id', $usuarios)->delete();

        return array_filter([
            'users' => $borrados,
            'model_has_roles' => $pivotes,
            'model_has_permissions' => $permisos,
        ]);
    }

    /**
     * El logo, si lo tenía.
     *
     * Fuera de la transacción: un archivo no se puede «deshacer» con un
     * rollback, así que se borra cuando la base ya confirmó. Si falla, la
     * empresa ya no está y un archivo huérfano no le hace daño a nadie —no
     * vale la pena tumbar la operación por eso—.
     */
    protected function borraElLogo(Company $empresa): void
    {
        $ruta = $empresa->logo_path;

        if (! $ruta) {
            return;
        }

        try {
            Storage::disk('public')->delete($ruta);
        } catch (\Throwable $e) {
            Log::warning('No se pudo borrar el logo de la empresa borrada', [
                'company_id' => $empresa->id,
                'path' => $ruta,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function existe(string $tabla): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($tabla);
    }
}
