<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const TYPES = [
        'activo' => 'Activo',
        'pasivo' => 'Pasivo',
        'patrimonio' => 'Patrimonio',
        'ingreso' => 'Ingreso',
        'gasto' => 'Gasto',
        'costo_venta' => 'Costo de Ventas',
        'costo_produccion' => 'Costo de Producción',
        'orden_deudora' => 'Cuentas de Orden Deudoras',
        'orden_acreedora' => 'Cuentas de Orden Acreedoras',
    ];

    public const TYPE_NATURE = [
        'activo' => 'debit',
        'pasivo' => 'credit',
        'patrimonio' => 'credit',
        'ingreso' => 'credit',
        'gasto' => 'debit',
        'costo_venta' => 'debit',
        'costo_produccion' => 'debit',
        'orden_deudora' => 'debit',
        'orden_acreedora' => 'credit',
    ];

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'nature',
        'parent_id',
        'level',
        'accepts_movements',
        'requires_third_party',
        'requires_cost_center',
        'active',
        'is_system',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'accepts_movements' => 'boolean',
            'requires_third_party' => 'boolean',
            'requires_cost_center' => 'boolean',
            'active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function fullName(): string
    {
        return "{$this->code} — {$this->name}";
    }

    public static function levelFromCode(string $code): int
    {
        return match (strlen($code)) {
            1 => 1,
            2 => 2,
            4 => 3,
            6 => 4,
            default => 5,
        };
    }

    public static function parentCodeOf(string $code): ?string
    {
        return match (strlen($code)) {
            1 => null,
            2 => substr($code, 0, 1),
            4 => substr($code, 0, 2),
            6 => substr($code, 0, 4),
            default => substr($code, 0, 6),
        };
    }

    public static function typeFromCode(string $code): string
    {
        return match ($code[0]) {
            '1' => 'activo',
            '2' => 'pasivo',
            '3' => 'patrimonio',
            '4' => 'ingreso',
            '5' => 'gasto',
            '6' => 'costo_venta',
            '7' => 'costo_produccion',
            '8' => 'orden_deudora',
            '9' => 'orden_acreedora',
            default => 'activo',
        };
    }
}
