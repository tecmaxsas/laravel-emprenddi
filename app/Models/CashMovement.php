<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada o salida directa de la caja.
 *
 * Es la plata que se mueve del cajón sin ser una venta: el pago del
 * domiciliario, la sangría a la caja fuerte, el cambio que hay que meter
 * porque se acabaron las monedas.
 */
class CashMovement extends Model
{
    use BelongsToCompany;

    public const IN = 'in';
    public const OUT = 'out';

    public const DIRECTIONS = [
        self::IN => 'Entrada',
        self::OUT => 'Salida',
    ];

    /**
     * Los motivos, y de qué cuenta del PUC cuelga cada uno.
     *
     * La lista es fija a propósito. Dejarla abierta significaría pedirle al
     * cajero que elija una cuenta contable para pagarle al domiciliario, y lo
     * que va a hacer es poner la primera que vea. Con motivos cerrados la
     * contrapartida se resuelve sola y además los reportes significan algo:
     * «cuánto sacamos en sangrías este mes» es una pregunta que se puede
     * contestar.
     *
     * `cuentas` lleva los códigos en orden de preferencia; se usa el primero
     * que exista en el PUC de la empresa. Es el mismo criterio de
     * `DefaultAccounts`, y por la misma razón: el plan de cuentas que se
     * siembra puede venir recortado.
     */
    public const REASONS = [
        // --- Entradas ---
        'base' => [
            'direction' => self::IN,
            'label' => 'Base adicional / cambio',
            'help' => 'Plata que se mete al cajón para tener con qué dar cambio.',
            // Viene del banco o de la caja fuerte: sale de allá y entra acá.
            'cuentas' => ['1110', '1105'],
        ],
        'aporte_socio' => [
            'direction' => self::IN,
            'label' => 'Aporte o préstamo del dueño',
            'help' => 'El dueño pone plata en la caja.',
            'cuentas' => ['2355', '3305', '2395'],
        ],
        'devolucion_empleado' => [
            'direction' => self::IN,
            'label' => 'Devolución de un empleado',
            'help' => 'Devuelve lo que le sobró de una diligencia.',
            'cuentas' => ['1365', '1330'],
        ],
        'otro_ingreso' => [
            'direction' => self::IN,
            'label' => 'Otro ingreso',
            'help' => 'Cualquier otra entrada de efectivo.',
            'cuentas' => ['4295', '4210'],
        ],

        // --- Salidas ---
        'sangria' => [
            'direction' => self::OUT,
            'label' => 'Sangría / traslado a caja fuerte',
            'help' => 'Sacar lo acumulado del cajón por seguridad. No es un gasto: la plata sigue siendo del negocio.',
            'cuentas' => ['1110', '1105'],
        ],
        'gasto_menor' => [
            'direction' => self::OUT,
            'label' => 'Gasto menor',
            'help' => 'El domicilio, la papelería, el agua. Para un gasto con factura y proveedor, usa Gastos.',
            'cuentas' => ['5195', '5295', '5135'],
        ],
        'pago_proveedor' => [
            'direction' => self::OUT,
            'label' => 'Pago a proveedor',
            'help' => 'Se le paga en efectivo del cajón.',
            'cuentas' => ['2205', '2335'],
        ],
        'adelanto_empleado' => [
            'direction' => self::OUT,
            'label' => 'Adelanto a un empleado',
            'help' => 'Plata que el empleado va a devolver o se le descuenta.',
            'cuentas' => ['1365', '1330'],
        ],
        'retiro_socio' => [
            'direction' => self::OUT,
            'label' => 'Retiro del dueño',
            'help' => 'El dueño saca plata de la caja.',
            'cuentas' => ['1330', '2355'],
        ],
        'otra_salida' => [
            'direction' => self::OUT,
            'label' => 'Otra salida',
            'help' => 'Cualquier otra salida de efectivo.',
            'cuentas' => ['5195', '5295'],
        ],
    ];

    protected $fillable = [
        'company_id',
        'cash_register_session_id',
        'location_id',
        'direction',
        'reason',
        'concept',
        'amount',
        'cash_account_id',
        'counterpart_account_id',
        'journal_entry_id',
        'third_party_id',
        'notes',
        'voided_at',
        'void_reason',
        'voided_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Los que cuentan para el arqueo.
     *
     * Un movimiento anulado no movió plata: la anulación revierte el asiento
     * y el efectivo vuelve a estar donde estaba.
     */
    public function scopeVivos(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeDelTurno(Builder $query, int $sessionId): Builder
    {
        return $query->where('cash_register_session_id', $sessionId)->vivos();
    }

    public function estaAnulado(): bool
    {
        return $this->voided_at !== null;
    }

    public function esEntrada(): bool
    {
        return $this->direction === self::IN;
    }

    /** Con signo: lo que le hace a la caja. */
    public function efectoEnCaja(): float
    {
        if ($this->estaAnulado()) {
            return 0.0;
        }

        return $this->esEntrada()
            ? (float) $this->amount
            : -(float) $this->amount;
    }

    public function etiquetaDelMotivo(): string
    {
        return self::REASONS[$this->reason]['label'] ?? $this->reason;
    }

    /** @return array<string, string> Los motivos de una dirección, para un desplegable. */
    public static function motivosDe(string $direction): array
    {
        $motivos = [];

        foreach (self::REASONS as $clave => $datos) {
            if ($datos['direction'] === $direction) {
                $motivos[$clave] = $datos['label'];
            }
        }

        return $motivos;
    }

    public static function esMotivoValido(?string $reason, ?string $direction = null): bool
    {
        if (! $reason || ! isset(self::REASONS[$reason])) {
            return false;
        }

        return $direction === null || self::REASONS[$reason]['direction'] === $direction;
    }
}
