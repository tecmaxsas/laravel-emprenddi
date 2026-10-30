<?php

namespace App\Models\Hotel;

use App\Models\CashRegisterSession;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La estadía: una cuenta abierta, no una transacción.
 *
 * El huésped entra, se le van cargando noches, el restaurante, la lavandería
 * y el minibar, y todo se cobra junto al salir. Eso es un folio, y se parece
 * a la orden de una mesa de restaurante mucho más que a una sesión de
 * parqueadero, que entra y sale en el mismo rato.
 *
 * Los totales son columnas y no se calculan al leer: el mapa de recepción
 * muestra el saldo de cada habitación ocupada, y sumar renglones en cada
 * pintada sería una consulta por habitación. Los escribe HotelFolio y
 * nadie más.
 */
class Stay extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_stays';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'Abierta',
        self::STATUS_CLOSED => 'Cerrada',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    protected $fillable = [
        'company_id',
        'hotel_id',
        'room_id',
        'room_type_id',
        'reservation_id',
        'third_party_id',
        'primary_guest_id',
        'prefix',
        'number',
        'check_in_at',
        'expected_check_out',
        'check_out_at',
        'nights',
        'adults',
        'children',
        'rate_per_night',
        'rate_is_fixed',
        'status',
        'tax_exempt',
        'tax_exempt_reason',
        'room_total',
        'consumption_total',
        'discount_total',
        'tax_total',
        'total',
        'paid_total',
        'sale_invoice_id',
        'cash_register_session_id',
        'notes',
        'cancel_reason',
        'created_by_user_id',
        'closed_by_user_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'check_in_at' => 'datetime',
            'expected_check_out' => 'date',
            'check_out_at' => 'datetime',
            'closed_at' => 'datetime',
            'nights' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'rate_per_night' => 'decimal:2',
            'rate_is_fixed' => 'boolean',
            'tax_exempt' => 'boolean',
            'room_total' => 'decimal:2',
            'consumption_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function primaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'primary_guest_id');
    }

    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'hotel_stay_guests', 'stay_id', 'guest_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function charges(): HasMany
    {
        return $this->hasMany(StayCharge::class, 'stay_id')
            ->orderBy('charge_date')
            ->orderBy('id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function estaAbierta(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Lo que falta por cobrar: el folio menos lo ya abonado. */
    public function saldo(): float
    {
        return round((float) $this->total - (float) $this->paid_total, 2);
    }

    /**
     * Noches que lleva dentro hasta hoy, sin importar lo pactado.
     *
     * Es lo que el pase nocturno usa para decidir cuántas noches van
     * cargadas, y lo que delata una estadía que se pasó de la fecha de salida.
     */
    public function nochesTranscurridas(): int
    {
        $desde = $this->check_in_at?->copy()->startOfDay();

        if (! $desde) {
            return 0;
        }

        $hasta = ($this->check_out_at ?: now())->copy()->startOfDay();

        return max(0, (int) $desde->diffInDays($hasta));
    }

    public function seQuedoDeMas(): bool
    {
        return $this->estaAbierta()
            && $this->expected_check_out
            && now()->startOfDay()->gt($this->expected_check_out->copy()->startOfDay());
    }

    public function nombreDelHuesped(): string
    {
        return $this->primaryGuest?->nombreCompleto()
            ?: ($this->customer?->name ?: 'Sin huésped registrado');
    }
}
