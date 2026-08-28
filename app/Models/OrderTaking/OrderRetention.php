<?php

namespace App\Models\OrderTaking;

use App\Models\Tax;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retencion aplicada a un pedido.
 *
 * Guarda el snapshot del Tax (codigo, nombre, tipo y tarifa) para que el
 * documento no cambie si despues alguien edita el impuesto en el catalogo.
 */
class OrderRetention extends Model
{
    use BelongsToCompany;

    protected $table = 'order_taking_order_retentions';

    protected $fillable = [
        'company_id', 'order_id', 'tax_id',
        'tax_code', 'tax_name', 'tax_type',
        'base_amount', 'rate', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
