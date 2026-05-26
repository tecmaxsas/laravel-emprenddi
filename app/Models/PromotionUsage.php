<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de cada uso de una promocion. Sirve para:
 *  - Validar max_uses_per_customer (cliente solo puede usar promo X veces)
 *  - Reporte de descuentos otorgados por promo
 *  - Auditoria — quien aplico que descuento a quien
 */
class PromotionUsage extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'promotion_id', 'sale_invoice_id',
        'customer_third_party_id', 'user_id',
        'discount_applied', 'applied_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'discount_applied' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'customer_third_party_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
