<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ThirdParty extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const PERSON_TYPES = [
        'natural' => 'Persona Natural',
        'juridica' => 'Persona Jurídica',
    ];

    public const DOCUMENT_TYPES = [
        'cc' => 'CC — Cédula de Ciudadanía',
        'ce' => 'CE — Cédula de Extranjería',
        'ti' => 'TI — Tarjeta de Identidad',
        'nit' => 'NIT — Número de Identificación Tributaria',
        'pasaporte' => 'Pasaporte',
        'rut' => 'RUT — Registro Único Tributario',
        'nuip' => 'NUIP — Número Único de Identificación Personal',
        'die' => 'DIE — Documento de Identificación Extranjero',
    ];

    public const REGIME_TYPES = [
        'comun' => 'Responsable de IVA',
        'no_responsable_iva' => 'No Responsable de IVA',
        'gran_contribuyente' => 'Gran Contribuyente',
        'simplificado' => 'Simplificado',
    ];

    /**
     * Responsabilidades tributarias DIAN — códigos oficiales del RUT.
     */
    public const TAX_RESPONSIBILITIES = [
        'O-13' => 'O-13 — Gran Contribuyente',
        'O-15' => 'O-15 — Autorretenedor',
        'O-23' => 'O-23 — Agente de retención IVA',
        'O-47' => 'O-47 — Régimen Simple de Tributación (RST)',
        'R-99-PN' => 'R-99-PN — No responsable',
        'ZA' => 'ZA — No aplica',
    ];

    protected $fillable = [
        'company_id',
        'person_type',
        'document_type',
        'document_number',
        'dv',
        'name',
        'legal_name',
        'first_name',
        'middle_name',
        'last_name',
        'second_last_name',
        'trade_name',
        'address',
        'city',
        'dian_municipality_id',
        'department',
        'country',
        'postal_code',
        'phone',
        'mobile',
        'email',
        'website',
        'contact_person',
        'contact_phone',
        'default_price_list_id',
        'business_name',
        'delivery_horario',
        'payment_terms',
        'retention_percent',
        'regime_type',
        'is_self_withholder',
        'is_iva_withholder',
        'is_ica_withholder',
        'tax_responsibilities',
        'is_customer',
        'is_supplier',
        'is_employee',
        'is_other',
        'default_receivable_account_id',
        'default_payable_account_id',
        'default_seller_user_id',
        'credit_limit',
        'credit_days',
        'payment_terms_days',
        'birth_date',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'tax_responsibilities' => 'array',
            'is_self_withholder' => 'boolean',
            'is_iva_withholder' => 'boolean',
            'is_ica_withholder' => 'boolean',
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'is_employee' => 'boolean',
            'is_other' => 'boolean',
            'credit_limit' => 'decimal:2',
            'credit_days' => 'integer',
            'payment_terms_days' => 'integer',
            'retention_percent' => 'decimal:4',
            'birth_date' => 'date',
            'active' => 'boolean',
        ];
    }

    public function defaultPriceList(): BelongsTo
    {
        return $this->belongsTo(\App\Models\OrderTaking\PriceList::class, 'default_price_list_id');
    }

    public function defaultReceivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_receivable_account_id');
    }

    public function defaultPayableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_payable_account_id');
    }

    public function defaultSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_seller_user_id');
    }

    public function fullDocument(): string
    {
        return $this->dv ? "{$this->document_number}-{$this->dv}" : $this->document_number;
    }

    public function rolesLabel(): string
    {
        $roles = [];
        if ($this->is_customer) $roles[] = 'Cliente';
        if ($this->is_supplier) $roles[] = 'Proveedor';
        if ($this->is_employee) $roles[] = 'Empleado';
        if ($this->is_other) $roles[] = 'Otro';

        return implode(', ', $roles) ?: '—';
    }
}
