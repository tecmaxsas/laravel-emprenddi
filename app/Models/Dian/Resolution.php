<?php

namespace App\Models\Dian;

use App\Models\Company;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resolution extends Model
{
    use BelongsToCompany;

    protected $table = 'dian_resolutions';

    public const KIND_ELECTRONIC = 'electronic';
    public const KIND_POS = 'pos';

    public const KINDS = [
        self::KIND_ELECTRONIC => 'Facturación Electrónica (DIAN)',
        self::KIND_POS => 'POS (sin transmisión a DIAN)',
    ];

    public const DOCUMENT_TYPES = [
        1 => 'Factura Electrónica',
        2 => 'Nota Crédito',
        3 => 'Nota Débito',
        4 => 'Documento Soporte',
        5 => 'Nómina Electrónica',
        6 => 'Factura de Exportación',
    ];

    protected $fillable = [
        'company_id',
        'kind',
        'document_type_id',
        'document_type_name',
        'prefix',
        'resolution_number',
        'resolution_date',
        'technical_key',
        'range_from',
        'range_to',
        'date_from',
        'date_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'document_type_id' => 'integer',
            'resolution_date' => 'date',
            'date_from' => 'date',
            'date_to' => 'date',
            'range_from' => 'integer',
            'range_to' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function isPos(): bool
    {
        return $this->kind === self::KIND_POS;
    }

    public function isElectronic(): bool
    {
        return $this->kind === self::KIND_ELECTRONIC;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function locationAssignments(): HasMany
    {
        return $this->hasMany(LocationResolution::class, 'dian_resolution_id');
    }

    public function rangeLabel(): string
    {
        return number_format($this->range_from).' → '.number_format($this->range_to);
    }
}
