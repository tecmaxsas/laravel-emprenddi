<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla de comision de un vendedor.
 *
 *  - scope=all      → % base del vendedor (aplica a todo)
 *  - scope=category → override para una categoria
 *  - scope=product  → override para un producto
 *
 * Al calcular la comision de una linea de venta, el motor resuelve la
 * tasa con esta prioridad: product > category > all. Si el vendedor no
 * tiene ninguna regla aplicable, esa linea no comisiona (0%).
 */
class CommissionRule extends Model
{
    use HasFactory, BelongsToCompany;

    public const SCOPE_ALL = 'all';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_PRODUCT = 'product';

    protected $fillable = [
        'company_id', 'seller_user_id',
        'scope', 'category_id', 'product_id',
        'rate', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function scopeForSeller(Builder $q, int $sellerId): Builder
    {
        return $q->where('seller_user_id', $sellerId);
    }
}
