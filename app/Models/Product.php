<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const TYPES = [
        'good' => 'Bien físico',
        'service' => 'Servicio',
        'kit' => 'Kit / Combo',
        'consumable' => 'Insumo / Consumible',
    ];

    public const COMMON_UNITS = [
        'unit' => 'Unidad',
        'kg' => 'Kilogramo',
        'g' => 'Gramo',
        'l' => 'Litro',
        'ml' => 'Mililitro',
        'm' => 'Metro',
        'cm' => 'Centímetro',
        'm2' => 'Metro cuadrado',
        'm3' => 'Metro cúbico',
        'box' => 'Caja',
        'pack' => 'Paquete',
        'hour' => 'Hora',
        'day' => 'Día',
        'service' => 'Servicio',
    ];

    protected $fillable = [
        'company_id',
        'code',
        'barcode',
        'name',
        'description',
        'category_id',
        'type',
        'unit_of_measure',
        'track_inventory',
        'is_purchasable',
        'is_sellable',
        'default_purchase_price',
        'default_sale_price',
        'min_sale_price',
        'default_purchase_tax_id',
        'default_sale_tax_id',
        'inventory_account_id',
        'sale_account_id',
        'cost_account_id',
        'image_path',
        'brand',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'track_inventory' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_sellable' => 'boolean',
            'active' => 'boolean',
            'default_purchase_price' => 'decimal:2',
            'default_sale_price' => 'decimal:2',
            'min_sale_price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function defaultPurchaseTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'default_purchase_tax_id');
    }

    public function defaultSaleTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'default_sale_tax_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function saleAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sale_account_id');
    }

    public function costAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cost_account_id');
    }

    public function productLocations(): HasMany
    {
        return $this->hasMany(ProductLocation::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'product_locations')
            ->withPivot([
                'active', 'min_stock', 'max_stock', 'reorder_point',
                'override_sale_price', 'override_purchase_price', 'shelf_location',
            ])
            ->withTimestamps();
    }

    public function fullName(): string
    {
        return "{$this->code} — {$this->name}";
    }

    public function priceForLocation(?Location $location = null): float
    {
        if ($location) {
            $pl = $this->productLocations()->where('location_id', $location->id)->first();
            if ($pl && $pl->override_sale_price !== null) {
                return (float) $pl->override_sale_price;
            }
        }

        return (float) $this->default_sale_price;
    }
}
