<?php

namespace App\Models\Hotel;

use App\Models\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un hotel. Independiente de Sedes, como el parqueadero: puede operarse sin
 * ser una "tienda", pero necesita una sede para facturar porque ahí viven la
 * resolución DIAN y el consecutivo.
 */
class Hotel extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotels';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'address',
        'city',
        'phone',
        'email',
        'rnt',
        'default_location_id',
        'default_invoice_kind',
        'check_in_time',
        'check_out_time',
        'settings',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'active' => 'boolean',
        ];
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(HotelZone::class, 'hotel_id')->orderBy('display_order');
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class, 'hotel_id')->orderBy('sort_order');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'hotel_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'hotel_id');
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class, 'hotel_id');
    }

    /**
     * La hora pactada de salida, como "12:00".
     *
     * Se guarda como TIME y Postgres lo devuelve con segundos; el motor la
     * necesita para armar un datetime y decidir si una salida tardía suma
     * noche, así que conviene que todos la lean igual.
     */
    public function checkOutTime(): string
    {
        return substr((string) ($this->check_out_time ?: '12:00:00'), 0, 5);
    }

    public function checkInTime(): string
    {
        return substr((string) ($this->check_in_time ?: '15:00:00'), 0, 5);
    }
}
