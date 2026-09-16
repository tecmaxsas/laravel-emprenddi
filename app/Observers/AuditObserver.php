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

        return $this->comparable($a) === $this->comparable($b);
    }

    /**
     * Deja cualquier valor en algo que se pueda comparar como texto.
     *
     * Los dos lados de la comparación no vienen del mismo sitio: el anterior
     * sale de `getOriginal()` y el nuevo de `getChanges()`, y Eloquent no
     * siempre les aplica el mismo casteo. En un campo casteado a `array` —como
     * la respuesta de la DIAN— uno llega como arreglo y el otro como el JSON
     * crudo de la base.
     *
     * Hacer `(string)` sobre eso mataba la petición entera con «Array to string
     * conversion», y como la bitácora se engancha a **todo** `update()`, el
     * error aparecía en la operación que se estuviera haciendo —enviar una nota
     * a la DIAN, por ejemplo— sin ninguna pista de que la auditoría tuviera algo
     * que ver.
     */
    private function comparable(mixed $valor): string
    {
        if (is_string($valor)) {
            // PostgreSQL reordena las claves de un `jsonb` al guardarlo, así que
            // el texto que devuelve no coincide con el que genera PHP aunque el
            // contenido sea idéntico. Sin normalizar, reenviar la misma
            // respuesta de la DIAN aparecería como un cambio, y ese ruido es lo
            // que hace que nadie lea la bitácora.
            $decodificado = json_decode($valor, true);

            return is_array($decodificado)
                ? $this->jsonCanonico($decodificado)
                : $valor;
        }

        if ($valor === null) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_scalar($valor)) {
            return (string) $valor;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        if ($valor instanceof \BackedEnum) {
            return (string) $valor->value;
        }

        if (is_object($valor) && method_exists($valor, '__toString')) {
            return (string) $valor;
        }

        // Arreglos y objetos sin representación de texto: el JSON sirve para
        // comparar, que es lo único que se necesita aquí.
        return is_array($valor)
            ? $this->jsonCanonico($valor)
            : (json_encode($valor, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * JSON con las claves ordenadas, para que dos estructuras iguales den el
     * mismo texto sin importar en qué orden vinieron.
     *
     * @param  array<array-key, mixed>  $valor
     */
    private function jsonCanonico(array $valor): string
    {
        $ordenar = function (array $datos) use (&$ordenar): array {
            ksort($datos);

            foreach ($datos as $clave => $dato) {
                if (is_array($dato)) {
                    $datos[$clave] = $ordenar($dato);
                }
            }

            return $datos;
        };

        return json_encode($ordenar($valor), JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function recortar(mixed $valor): mixed
    {
        if (is_string($valor) && mb_strlen($valor) > AuditRegistry::MAX_LARGO_VALOR) {
            return mb_substr($valor, 0, AuditRegistry::MAX_LARGO_VALOR).'…';
        }

        if (is_array($valor)) {
            return $this->recortar(json_encode($valor, JSON_UNESCAPED_UNICODE));
        }

        // Un objeto se guardaría con toda su estructura interna —una fecha se
        // vuelve `{"date":…,"timezone_type":3,…}`— y quien lee la bitácora no
        // entiende nada. Se guarda como se lee.
        if (is_object($valor)) {
            return $this->recortar($this->comparable($valor));
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
