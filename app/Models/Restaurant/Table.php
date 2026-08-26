<?php

namespace App\Models\Restaurant;

use App\Models\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Table extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_tables';

    public const STATUSES = [
        'free' => 'Libre',
        'occupied' => 'Ocupada',
        'reserved' => 'Reservada',
        'billing' => 'Pidiendo cuenta',
        'cleaning' => 'Limpieza',
    ];

    public const SHAPES = [
        'square' => 'Cuadrada',
        'round' => 'Redonda',
        'rect' => 'Rectangular',
        'bar' => 'Barra',
    ];

    /**
     * Alto de la banda con la que se agrupan las filas del mapa, sobre el
     * lienzo de 0..1000. 50 = 5% del alto, mas o menos una mesa.
     */
    public const MAP_ROW_BAND = 50;

    protected $fillable = [
        'company_id',
        'location_id',
        'zone_id',
        'code',
        'label',
        'capacity',
        'shape',
        'pos_x',
        'pos_y',
        'width',
        'height',
        'status',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'zone_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    public function activeOrder(): ?Order
    {
        return $this->orders()
            ->whereIn('status', ['open', 'in_kitchen', 'served', 'billing'])
            ->latest('opened_at')
            ->first();
    }

    public function isFree(): bool
    {
        return $this->status === 'free';
    }

    public function isOccupied(): bool
    {
        return in_array($this->status, ['occupied', 'billing'], true);
    }

    /**
     * Clave de orden que reproduce como se ve el mapa del salon: se lee por
     * filas, de arriba a abajo y de izquierda a derecha.
     *
     * pos_y se agrupa en bandas porque las mesas se ubican arrastrando y las
     * de una misma fila casi nunca quedan a la misma altura exacta; sin las
     * bandas, unos pocos pixeles de diferencia bastarian para desordenar la
     * fila entera.
     *
     * El codigo desempata cuando las posiciones coinciden — el caso de una
     * sede donde nadie ha tocado el mapa y todas siguen en el default (50,50).
     * Va con los numeros rellenos para que ordene 1, 2, 10 y no 1, 10, 2.
     */
    public function mapOrderKey(): string
    {
        $banda = intdiv((int) $this->pos_y, self::MAP_ROW_BAND);

        return sprintf(
            '%04d|%04d|%s',
            $banda,
            (int) $this->pos_x,
            preg_replace_callback(
                '/\d+/',
                fn (array $m) => str_pad($m[0], 10, '0', STR_PAD_LEFT),
                (string) $this->code,
            ),
        );
    }
}
