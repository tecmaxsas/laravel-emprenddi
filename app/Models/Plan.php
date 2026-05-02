<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Plan extends Model
{
    use HasFactory, SoftDeletes;

    public const FEATURE_KEYS = [
        'electronic_billing' => 'Facturación Electrónica DIAN',
        'restaurant' => 'Módulo Restaurante (mesas, comandas, KDS)',
        'pharmacy' => 'Módulo Farmacia (lotes, vencimientos)',
        'retail' => 'Módulo Retail (variantes, tallas, colores)',
        'services' => 'Módulo Servicios (proyectos, contratos)',
        'payroll' => 'Nómina Electrónica',
        'assets' => 'Activos Fijos',
        'multi_currency' => 'Multi-moneda',
        'ecommerce' => 'E-commerce',
        'delivery' => 'Domicilios',
    ];

    public const LIMIT_KEYS = [
        'max_locations' => 'Sedes / Bodegas',
        'max_users' => 'Usuarios',
        'max_products' => 'Productos',
        'max_invoices_per_month' => 'Facturas por mes',
        'max_third_parties' => 'Terceros (clientes/proveedores)',
        'max_storage_mb' => 'Almacenamiento (MB)',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'billing_cycle',
        'currency',
        'trial_days',
        'features',
        'limits',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Plan $plan) {
            if (empty($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function isUnlimited(string $key): bool
    {
        return $this->limit($key) === null;
    }
}
