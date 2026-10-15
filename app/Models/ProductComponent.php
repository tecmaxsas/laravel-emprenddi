<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pieza de un combo, con su cantidad.
 *
 * Dos de estas dicen «este combo lleva 1 hamburguesa y 2 gaseosas». Al vender
 * el combo salen del almacén esas cantidades, multiplicadas por cuántos combos
 * se vendieron.
 */
class ProductComponent extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'kit_product_id',
        'component_product_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'kit_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
