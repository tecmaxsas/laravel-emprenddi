<?php

namespace App\Models\Hotel;

use App\Models\ThirdParty;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Huésped: quién durmió en el hotel.
 *
 * No es un Tercero. El Tercero es a quién se le factura —puede ser la empresa
 * que paga la habitación de su empleado—; el huésped es la persona, y de ella
 * la ley pide datos que a la contabilidad no le sirven: nacionalidad,
 * procedencia, destino y motivo del viaje. Es la tarjeta de registro
 * hotelero. Cuando el huésped también paga, `third_party_id` los enlaza.
 */
class Guest extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_guests';

    public const PURPOSES = [
        'leisure' => 'Turismo / descanso',
        'business' => 'Negocios / trabajo',
        'health' => 'Salud',
        'study' => 'Estudio',
        'family' => 'Visita familiar',
        'transit' => 'Tránsito',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'company_id',
        'third_party_id',
        'document_type',
        'document_number',
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'nationality',
        'email',
        'phone',
        'address',
        'city',
        'origin',
        'destination',
        'travel_purpose',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function stays(): BelongsToMany
    {
        return $this->belongsToMany(Stay::class, 'hotel_stay_guests', 'guest_id', 'stay_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function nombreCompleto(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function documento(): string
    {
        if (! $this->document_number) {
            return 'Sin documento';
        }

        return strtoupper((string) $this->document_type).' '.$this->document_number;
    }

    /**
     * Si es extranjero. Lo que decide si el hospedaje puede ir exento de IVA
     * (art. 481 ET) y si hay que reportarlo a Migración.
     *
     * Se resuelve por nacionalidad y por tipo de documento: un pasaporte o una
     * cédula de extranjería ya lo dicen aunque no se haya digitado el país.
     */
    public function esExtranjero(): bool
    {
        if (in_array(strtolower((string) $this->document_type), ['pa', 'ce'], true)) {
            return true;
        }

        $nacionalidad = strtolower(trim((string) $this->nationality));

        return $nacionalidad !== '' && ! in_array($nacionalidad, ['co', 'col', 'colombia', 'colombiana', 'colombiano'], true);
    }

    public function edad(): ?int
    {
        return $this->birth_date ? $this->birth_date->age : null;
    }
}
