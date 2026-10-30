<?php

namespace App\Models\Hotel;

use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un bloque de reservas: el matrimonio, el congreso, la empresa.
 *
 * No reemplaza a la reserva, la envuelve. Cada habitación sigue teniendo la
 * suya —con su check-in y su folio— porque así funciona de verdad: llegan en
 * horas distintas, uno se va antes, otro pide cambiarse. Lo que el grupo
 * aporta es crearlas de una vez, verlas juntas y **facturarlas en un solo
 * documento**.
 */
class ReservationGroup extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_reservation_groups';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'Abierto',
        self::STATUS_CLOSED => 'Cerrado',
        self::STATUS_CANCELLED => 'Cancelado',
    ];

    protected $fillable = [
        'company_id',
        'hotel_id',
        'code',
        'name',
        'third_party_id',
        'contact_name',
        'contact_phone',
        'contact_email',
        'check_in_date',
        'check_out_date',
        'source',
        'status',
        'bill_together',
        'sale_invoice_id',
        'notes',
        'cancel_reason',
        'created_by_user_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'bill_together' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'group_id');
    }

    /**
     * Las estadías del grupo: las de sus reservas que ya hicieron check-in.
     *
     * Se llega a ellas por la reserva y no por una columna propia en la
     * estadía, porque la reserva es la que de verdad pertenece al bloque. Una
     * columna más sería otra cosa que mantener sincronizada.
     *
     * @return Collection<int, Stay>
     */
    public function stays(): Collection
    {
        return Stay::query()
            ->whereIn('reservation_id', $this->reservations()->select('id'))
            ->with(['room:id,number', 'primaryGuest:id,first_name,last_name'])
            ->get();
    }

    /** Las que siguen adentro y hay que cobrar. */
    public function openStays(): Collection
    {
        return $this->stays()->filter(fn (Stay $e) => $e->estaAbierta())->values();
    }

    public function scopeAbiertos(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function estaAbierto(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function noches(): int
    {
        return max(1, (int) $this->check_in_date->diffInDays($this->check_out_date));
    }

    /**
     * Cómo va el bloque, de un vistazo.
     *
     * Es lo que recepción necesita el día de la llegada: cuántos faltan por
     * entrar y cuántos ya están adentro.
     *
     * @return array{habitaciones: int, vivas: int, alojados: int, canceladas: int, estimado: float}
     */
    public function resumen(): array
    {
        $reservas = $this->reservations;

        return [
            'habitaciones' => $reservas->count(),
            'vivas' => $reservas->filter(fn (Reservation $r) => $r->estaViva())->count(),
            'alojados' => $reservas->where('status', Reservation::STATUS_CHECKED_IN)->count(),
            'canceladas' => $reservas->whereIn('status', [
                Reservation::STATUS_CANCELLED,
                Reservation::STATUS_NO_SHOW,
            ])->count(),
            'estimado' => round((float) $reservas->sum('estimated_total'), 2),
        ];
    }
}
