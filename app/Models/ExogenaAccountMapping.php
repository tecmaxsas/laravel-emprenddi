<?php

namespace App\Models;

use App\Services\Exogena\ExogenaCatalog;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mapeo de una cuenta del PUC a un concepto de un formato de información
 * exógena. El motor de exógena usa estas filas para clasificar los
 * movimientos contables por concepto.
 */
class ExogenaAccountMapping extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'format_code',
        'concept_code',
        'value_column',
        'account_id',
        'notes',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function formatName(): string
    {
        return ExogenaCatalog::formatLabel($this->format_code);
    }

    public function conceptName(): string
    {
        $name = ExogenaCatalog::conceptName($this->format_code, $this->concept_code);

        return $name ? $this->concept_code.' — '.$name : (string) $this->concept_code;
    }
}
