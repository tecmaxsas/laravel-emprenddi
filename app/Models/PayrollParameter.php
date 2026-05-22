<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Parámetros legales de nómina por año: salario mínimo (SMMLV), auxilio
 * de transporte y UVT. El Gobierno los fija cada año.
 */
class PayrollParameter extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'year',
        'smmlv',
        'transport_allowance',
        'uvt',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'smmlv' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'uvt' => 'decimal:2',
        ];
    }

    public static function forYear(int $year): ?self
    {
        return static::query()->where('year', $year)->first();
    }
}
