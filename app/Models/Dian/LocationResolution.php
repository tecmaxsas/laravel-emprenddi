<?php

namespace App\Models\Dian;

use App\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationResolution extends Model
{
    protected $table = 'dian_location_resolutions';

    protected $fillable = [
        'location_id',
        'dian_resolution_id',
        'current_consecutive',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'current_consecutive' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'dian_resolution_id');
    }

    /**
     * Reserva el siguiente consecutivo de forma atómica e incrementa.
     * Devuelve el número que debe usarse en el documento que se está creando.
     */
    public function reserveNextNumber(): int
    {
        return \DB::transaction(function () {
            $row = self::where('id', $this->id)->lockForUpdate()->first();
            $current = $row->current_consecutive;
            $row->update(['current_consecutive' => $current + 1]);

            return $current;
        });
    }
}
