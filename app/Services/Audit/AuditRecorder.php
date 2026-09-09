<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El único punto por donde se escribe en la bitácora.
 *
 * Dos reglas gobiernan todo lo de aquí:
 *
 * 1. **Auditar nunca puede tumbar la operación.** Si escribir la bitácora
 *    falla, se anota en el log de la aplicación y la venta sigue. Un cajero no
 *    puede quedarse sin facturar porque la auditoría tuvo un problema.
 *
 * 2. **Se escribe fuera de la transacción del negocio.** Si el asiento contable
 *    se revierte, el intento no debería quedar registrado como si hubiera
 *    ocurrido — y al revés, un rollback del negocio no debe borrar el rastro de
 *    lo que sí pasó. Por eso las entradas se acumulan y se guardan al hacer
 *    commit; sin transacción abierta, se guardan de una vez.
 */
class AuditRecorder
{
    /**
     * Entradas a la espera del commit, con el nivel de transacción en que se
     * generaron: `[['nivel' => int, 'entrada' => array], …]`.
     *
     * @var list<array{nivel: int, entrada: array<string, mixed>}>
     */
    private array $pendientes = [];

    /** Permite apagar la auditoría en pruebas y migraciones de datos. */
    private bool $activo = true;

    public function pausar(): void
    {
        $this->activo = false;
    }

    public function reanudar(): void
    {
        $this->activo = true;
    }

    public function activo(): bool
    {
        return $this->activo;
    }

    /** Un cambio sobre un modelo del negocio. */
    public function modelo(Model $modelo, string $evento, ?array $cambios = null): void
    {
        if (! $this->activo || ! AuditRegistry::audita($modelo)) {
            return;
        }

        // Un `updated` sin campos relevantes es ruido: pasó un touch, o solo
        // cambió `updated_at`. No merece una línea en la bitácora.
        if ($evento === AuditLog::EVENT_UPDATED && empty($cambios)) {
            return;
        }

        $this->registrar([
            'company_id' => $this->empresaDe($modelo),
            'event' => $evento,
            'auditable_type' => $modelo::class,
            'auditable_id' => $modelo->getKey(),
            'auditable_label' => AuditRegistry::describir($modelo),
            'changes' => $cambios,
        ]);
    }

    /** Un evento de sesión: entrar, salir, o fallar al entrar. */
    public function sesion(string $evento, ?User $usuario = null, ?int $companyId = null, ?array $datos = null): void
    {
        if (! $this->activo) {
            return;
        }

        $this->registrar([
            'company_id' => $companyId ?? $usuario?->company_id ?? $this->empresaActual(),
            'event' => $evento,
            'changes' => $datos,
        ], $usuario);
    }

    /**
     * Arma la entrada completa y la encola o la guarda.
     *
     * @param  array<string, mixed>  $datos
     */
    private function registrar(array $datos, ?User $usuario = null): void
    {
        $usuario ??= Auth::user();

        // Sin empresa no hay a quién mostrarle esto: la pantalla filtra por
        // empresa y una fila sin dueño sería invisible y además rompería la FK.
        if (! $datos['company_id']) {
            return;
        }

        $peticion = request();

        $entrada = array_merge($datos, [
            'user_id' => $usuario?->getKey(),
            'user_name' => $usuario?->name,
            'user_email' => $usuario?->email,
            'ip_address' => $peticion?->ip(),
            'user_agent' => mb_substr((string) $peticion?->userAgent(), 0, 255) ?: null,
            'url' => mb_substr((string) $peticion?->fullUrl(), 0, 255) ?: null,
            'created_at' => now(),
        ]);

        $nivel = DB::transactionLevel();

        if ($nivel > 0) {
            // Se anota el nivel en el que nació para poder descartarla si ese
            // nivel se revierte. Ver descartarDesde().
            $this->pendientes[] = ['nivel' => $nivel, 'entrada' => $entrada];

            // Una devolución de llamada por entrada: `volcar()` vacía la cola,
            // así que las siguientes no hacen nada. Sale más barato que llevar
            // una bandera, que además se quedaba encendida tras un rollback y
            // arrastraba lo descartado al siguiente commit.
            DB::afterCommit(fn () => $this->volcar());

            return;
        }

        $this->guardar([$entrada]);
    }

    /** Vuelca a la base todo lo acumulado. Se llama al hacer commit. */
    private function volcar(): void
    {
        if ($this->pendientes === []) {
            return;
        }

        $entradas = array_column($this->pendientes, 'entrada');
        $this->pendientes = [];

        $this->guardar($entradas);
    }

    /**
     * Descarta lo encolado por encima de un nivel de transacción.
     *
     * Lo llama el escucha de `TransactionRolledBack`. Con el nivel se respetan
     * las transacciones anidadas: si se revierte un savepoint interno, lo que
     * hizo la transacción externa —que sigue viva— no se pierde.
     */
    public function descartarDesde(int $nivel): void
    {
        $this->pendientes = array_values(array_filter(
            $this->pendientes,
            fn (array $p) => $p['nivel'] <= $nivel,
        ));
    }

    /** @param  list<array<string, mixed>>  $entradas */
    private function guardar(array $entradas): void
    {
        if ($entradas === []) {
            return;
        }

        try {
            // Por referencia a propósito: se está reescribiendo la entrada, no
            // leyéndola. Sin el `&`, `changes` viajaba como arreglo PHP a un
            // insert de SQL y la fila se perdía en silencio.
            foreach ($entradas as &$entrada) {
                $entrada['changes'] = $entrada['changes'] === null || $entrada['changes'] === []
                    ? null
                    : json_encode($entrada['changes'], JSON_UNESCAPED_UNICODE);
            }
            unset($entrada);

            DB::table((new AuditLog)->getTable())->insert($entradas);
        } catch (Throwable $e) {
            // Regla 1: la auditoría no tumba la operación.
            Log::warning('[Auditoría] No se pudo escribir la bitácora: '.$e->getMessage(), [
                'entradas' => count($entradas),
            ]);

            // Salvo en pruebas. Tragarse el error en producción es correcto;
            // tragárselo en el banco de pruebas convierte una bitácora rota en
            // una suite verde, que fue justo lo que pasó la primera vez.
            if (app()->runningUnitTests()) {
                throw $e;
            }
        }
    }

    /** La empresa del registro; si no la tiene, la del contexto. */
    private function empresaDe(Model $modelo): ?int
    {
        $propia = $modelo->getAttribute('company_id');

        return $propia ? (int) $propia : $this->empresaActual();
    }

    private function empresaActual(): ?int
    {
        $id = app(CurrentCompany::class)->scopeId();

        return $id ?: null;
    }
}
