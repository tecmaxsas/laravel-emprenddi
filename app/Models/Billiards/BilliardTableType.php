<?php

namespace App\Models\Billiards;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de mesa: pool, tres bandas, mini pool, rana, ping pong.
 *
 * Es lo que separa las tarifas. En un salon colombiano la hora de una mesa
 * de tres bandas vale casi el doble que la de un pool, y la rana se cobra
 * por partida y no por tiempo.
 */
class BilliardTableType extends Model
{
    use BelongsToCompany;

    public const CODE_POOL = 'POOL';
    public const CODE_TRES_BANDAS = 'TRES_BANDAS';
    public const CODE_MINIPOOL = 'MINIPOOL';
    public const CODE_RANA = 'RANA';
    public const CODE_PINGPONG = 'PINGPONG';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'icon',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(BilliardTable::class, 'table_type_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(BilliardRate::class, 'table_type_id');
    }
}
