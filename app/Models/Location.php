<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const TYPES = [
        'store' => 'Tienda / Punto de venta',
        'warehouse' => 'Bodega',
        'mixed' => 'Mixta (tienda + bodega)',
        'virtual' => 'Virtual / E-commerce',
    ];

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'is_main',
        'manager_user_id',
        'invoice_template_id',
        'address',
        'city',
        'department',
        'country',
        'postal_code',
        'phone',
        'email',
        'currency',
        'timezone',
        'settings',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'active' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Location $location) {
            // Solo una sede principal por empresa: si ésta se marca main, desmarca las otras.
            if ($location->is_main) {
                static::where('company_id', $location->company_id)
                    ->where('id', '!=', $location->id ?? 0)
                    ->where('is_main', true)
                    ->update(['is_main' => false]);
            }
        });
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function invoiceTemplate(): BelongsTo
    {
        return $this->belongsTo(InvoiceTemplate::class);
    }

    /**
     * Resuelve el template a usar para esta sede.
     * Orden: el asignado explícitamente → el default de la empresa → primero activo.
     */
    public function resolveInvoiceTemplate(): ?InvoiceTemplate
    {
        if ($this->invoice_template_id) {
            return $this->invoiceTemplate;
        }

        return InvoiceTemplate::query()
            ->where('company_id', $this->company_id)
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    public function fullName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
