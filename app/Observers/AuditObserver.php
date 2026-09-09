<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Traduce los eventos de Eloquent a entradas de bitácora.
 *
 * Se engancha a los modelos de AuditRegistry desde AppServiceProvider. No lleva
 * estado: todo lo que necesita saber está en el modelo que recibe.
 */
class AuditObserver
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function created(Model $modelo): void
    {
        $this->recorder->modelo($modelo, AuditLog::EVENT_CREATED, $this->valoresIniciales($modelo));
    }

    public function updated(Model $modelo): void
    {
        // Un soft delete llega a Eloquent como un `updated` de `deleted_at`.
        // Para quien lee la bitácora eso es un borrado, no una modificación.
        if ($this->esBorradoLogico($modelo)) {
            $this->recorder->modelo($modelo, AuditLog::EVENT_DELETED);

            return;
        }

        // Restaurar sí pasa por `save()`, así que llega aquí y además dispara
        // `restored`. Se anota una sola vez, allá.
        if ($this->esRestauracion($modelo)) {
            return;
        }

        $this->recorder->modelo($modelo, AuditLog::EVENT_UPDATED, $this->diferencias($modelo));
    }

    public function deleted(Model $modelo): void
    {
        // Vale tanto para el borrado lógico como para el definitivo. El
        // borrado lógico no pasa por `updated` —SoftDeletes actualiza con el
        // query builder, no con el modelo— así que este es su único aviso.
        $this->recorder->modelo($modelo, AuditLog::EVENT_DELETED);
    }

    public function restored(Model $modelo): void
    {
        $this->recorder->modelo($modelo, AuditLog::EVENT_RESTORED);
    }

    /**
     * Qué cambió, con valor anterior y nuevo.
     *
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    private function diferencias(Model $modelo): array
    {
        $cambios = [];

        foreach ($modelo->getChanges() as $campo => $nuevo) {
            if ($this->esOculto($campo)) {
                continue;
            }

            $anterior = $modelo->getOriginal($campo);

            // Eloquent marca como cambiado un 100 que pasó a "100.00". Para
            // quien audita eso no es un cambio.
            if ($this->equivalentes($anterior, $nuevo)) {
                continue;
            }

            $cambios[$campo] = [
                'antes' => $this->recortar($anterior),
                'despues' => $this->recortar($nuevo),
            ];
        }

        return $cambios;
    }

    /**
     * En un `created` no hay «antes»: se guarda el estado con el que nació,
     * sin los campos ocultos.
     *
     * @return array<string, mixed>
     */
    private function valoresIniciales(Model $modelo): array
    {
        $valores = [];

        foreach ($modelo->getAttributes() as $campo => $valor) {
            if ($this->esOculto($campo) || $valor === null) {
                continue;
            }

            $valores[$campo] = $this->recortar($valor);
        }

        return $valores;
    }

    private function esOculto(string $campo): bool
    {
        return in_array($campo, AuditRegistry::CAMPOS_OCULTOS, true);
    }

    /** Compara sin castigar cambios de formato de un mismo número. */
    private function equivalentes(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return (string) $a === (string) $b;
    }

    private function recortar(mixed $valor): mixed
    {
        if (is_string($valor) && mb_strlen($valor) > AuditRegistry::MAX_LARGO_VALOR) {
            return mb_substr($valor, 0, AuditRegistry::MAX_LARGO_VALOR).'…';
        }

        if (is_array($valor)) {
            return $this->recortar(json_encode($valor, JSON_UNESCAPED_UNICODE));
        }

        return $valor;
    }

    private function usaBorradoLogico(Model $modelo): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelo), true);
    }

    private function esBorradoLogico(Model $modelo): bool
    {
        return $this->usaBorradoLogico($modelo)
            && array_key_exists('deleted_at', $modelo->getChanges())
            && $modelo->getAttribute('deleted_at') !== null;
    }

    private function esRestauracion(Model $modelo): bool
    {
        return $this->usaBorradoLogico($modelo)
            && array_key_exists('deleted_at', $modelo->getChanges())
            && $modelo->getAttribute('deleted_at') === null;
    }
}
