<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidad física serializada de un producto. Cada fila representa una
 * unidad concreta con un número de serie, vive en una sede y atraviesa
 * estados (in_stock → sold | returned | defective).
 *
 * Los timestamps received_at / sold_at son distintos a created_at /
 * updated_at porque la fila puede crearse al postear la factura de
 * compra (received_at = invoice.date) o por backfill posterior.
 */
class ProductSerial extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_SOLD = 'sold';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_DEFECTIVE = 'defective';

    public const STATUS_RESERVED = 'reserved';

    public const STATUSES = [
        self::STATUS_IN_STOCK => 'En stock',
        self::STATUS_SOLD => 'Vendido',
        self::STATUS_RETURNED => 'Devuelto',
        self::STATUS_DEFECTIVE => 'Defectuoso',
        self::STATUS_RESERVED => 'Reservado',
    ];

    protected $fillable = [
        'company_id',
        'product_id',
        'location_id',
        'serial_number',
        'status',
        'purchase_invoice_line_id',
        'sale_invoice_line_id',
        'inventory_adjustment_line_id',
        'received_at',
        'sold_at',
        'notes',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'sold_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'purchase_invoice_line_id');
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleInvoiceLine::class, 'sale_invoice_line_id');
    }

    public function adjustmentLine(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustmentLine::class, 'inventory_adjustment_line_id');
    }

    public function purchaseInvoice(): ?PurchaseInvoice
    {
        return $this->purchaseLine?->invoice;
    }

    public function saleInvoice(): ?SaleInvoice
    {
        return $this->saleLine?->invoice;
    }
}
