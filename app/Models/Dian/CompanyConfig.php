<?php

namespace App\Models\Dian;

use App\Models\Company;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class CompanyConfig extends Model
{
    protected $table = 'dian_company_configs';

    public const ENV_PRODUCTION = 1;
    public const ENV_TEST = 2;

    public const ENVIRONMENTS = [
        self::ENV_PRODUCTION => 'Producción',
        self::ENV_TEST => 'Pruebas',
    ];

    protected $fillable = [
        'company_id',
        'api_token',
        'api_url',
        'environment',
        'dian_document_type_id',
        'dian_organization_type_id',
        'dian_regime_type_id',
        'dian_tax_liability_id',
        'dian_municipality_id',
        'merchant_registration',
        'software_id',
        'software_pin',
        'certificate_filename',
        'certificate_password',
        'certificate_expedition_date',
        'certificate_expiration_date',
        'company_registered',
        'software_configured',
        'certificate_uploaded',
    
        'software_payroll_id',
        'software_payroll_pin',
        'payroll_software_configured',
        'payroll_test_set_id',
        'payroll_test_consecutive',
        'payroll_environment',
];

    protected function casts(): array
    {
        return [
            'payroll_software_configured' => 'boolean',
            'payroll_environment' => 'integer',
            'environment' => 'integer',
            'company_registered' => 'boolean',
            'software_configured' => 'boolean',
            'certificate_uploaded' => 'boolean',
            'certificate_expedition_date' => 'date',
            'certificate_expiration_date' => 'date',
        ];
    }

    /**
     * Días restantes hasta el vencimiento del certificado.
     * Devuelve null si no hay fecha. Negativo si ya vencido.
     */
    public function daysToCertificateExpiration(): ?int
    {
        if (! $this->certificate_expiration_date) {
            return null;
        }
        return (int) round(now()->startOfDay()->diffInDays($this->certificate_expiration_date->startOfDay(), false));
    }

    /**
     * Encripta software_pin al guardar y desencripta al leer.
     */
    protected function softwarePin(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * Encripta certificate_password al guardar y desencripta al leer.
     */
    protected function certificatePassword(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'dian_document_type_id');
    }

    public function organizationType(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'dian_organization_type_id');
    }

    public function regimeType(): BelongsTo
    {
        return $this->belongsTo(RegimeType::class, 'dian_regime_type_id');
    }

    public function taxLiability(): BelongsTo
    {
        return $this->belongsTo(TaxLiability::class, 'dian_tax_liability_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'dian_municipality_id');
    }

    public function isFullyConfigured(): bool
    {
        return $this->company_registered
            && $this->software_configured
            && $this->certificate_uploaded;
    }
}
