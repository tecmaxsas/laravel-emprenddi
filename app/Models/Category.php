<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'default_sale_account_id',
        'default_cost_account_id',
        'default_inventory_account_id',
        'code',
        'name',
        'slug',
        'description',
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

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function defaultSaleAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_sale_account_id');
    }

    public function defaultCostAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_cost_account_id');
    }

    public function defaultInventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_inventory_account_id');
    }

    public function fullName(): string
    {
        return $this->parent ? "{$this->parent->name} › {$this->name}" : $this->name;
    }

    /**
     * Resuelve la cuenta/impuesto subiendo por la jerarquía padre hasta
     * encontrar valor. Si nadie en la cascada lo define, devuelve null.
     */
    public function resolveDefault(string $field): ?int
    {
        if (! in_array($field, [
            'default_sale_account_id',
            'default_cost_account_id',
            'default_inventory_account_id',
        ], true)) {
            return null;
        }

        $current = $this;
        $depth = 0;
        while ($current && $depth < 10) { // limite de seguridad para ciclos
            $value = $current->{$field};
            if ($value !== null) {
                return (int) $value;
            }
            $current = $current->parent;
            $depth++;
        }

        return null;
    }
}
