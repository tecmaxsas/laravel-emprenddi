<?php

namespace App\Models;

use App\Services\Audit\AuditRegistry;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Una entrada de la bitácora de auditoría.
 *
 * Append-only, y no por convención: el modelo bloquea `update` y `delete`. Una
 * bitácora que el propio sistema puede reescribir no prueba nada, y el día que
 * alguien quiera «arreglar» un registro incómodo va a encontrar la puerta
 * cerrada. Para purgar lo viejo está el comando `audit:purge`, que borra por
 * fecha con SQL directo y deja constancia.
 */
class AuditLog extends Model
{
    use BelongsToCompany;

    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_DELETED = 'deleted';

    public const EVENT_RESTORED = 'restored';

    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_LOGIN_FAILED = 'login_failed';

    /** Cómo se lee cada evento en la pantalla. */
    public const EVENTS = [
        self::EVENT_CREATED => 'Creó',
        self::EVENT_UPDATED => 'Modificó',
        self::EVENT_DELETED => 'Eliminó',
        self::EVENT_RESTORED => 'Restauró',
        self::EVENT_LOGIN => 'Inició sesión',
        self::EVENT_LOGOUT => 'Cerró sesión',
        self::EVENT_LOGIN_FAILED => 'Intento de acceso fallido',
    ];

    /** Sin `updated_at`: una entrada nunca se modifica. */
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'user_name',
        'user_email',
        'event',
        'auditable_type',
        'auditable_id',
        'auditable_label',
        'changes',
        'ip_address',
        'user_agent',
        'url',
        'created_at',
    ];

    /**
     * `changes` coincide de nombre con una propiedad interna de Eloquent (la
     * que alimenta `getChanges()`). Funciona porque esa propiedad es protegida
     * y desde fuera `$log->changes` cae en `__get()` y lee el atributo. Si
     * alguna vez se necesita leerla DENTRO de esta clase, hay que pedirla con
     * `getAttribute('changes')`: `$this->changes` devolvería la otra.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'changes' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Un registro de auditoría no se puede modificar.');
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Un registro de auditoría no se puede eliminar uno por uno. '
                .'Para depurar la bitácora antigua usa «php artisan audit:purge».'
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** «Creó», «Modificó»… o el código crudo si aparece uno nuevo. */
    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }

    /** Quién: el nombre guardado en su momento, aunque el usuario ya no exista. */
    public function actorLabel(): string
    {
        return $this->user_name ?: ($this->user?->name ?: 'Sistema');
    }

    /**
     * Qué, en una línea: «Modificó Producto PERF-001 — Eau de Parfum».
     * Los eventos de sesión no tienen objeto, y no hace falta inventarles uno.
     */
    public function summary(): string
    {
        if (! $this->auditable_type) {
            return $this->eventLabel();
        }

        $modelo = AuditRegistry::label($this->auditable_type);

        return trim($this->eventLabel().' '.$modelo.' '.($this->auditable_label ?? ''));
    }
}
