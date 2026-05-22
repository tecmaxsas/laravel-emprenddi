<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro manual de un formato de exógena que no sale del libro contable
 * (formatos 1004 y 1011). El concepto se digita libremente según la
 * resolución DIAN del año.
 */
class ExogenaManualEntry extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'fiscal_year',
        'format_code',
        'concept_code',
        'concept_name',
        'amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'amount' => 'decimal:2',
        ];
    }
}
