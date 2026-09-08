<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retención que la empresa le practica a un proveedor.
 *
 * Al revés que la de venta: aquí somos el agente retenedor, así que el monto
 * es un pasivo con la DIAN y se le descuenta al proveedor de lo que se le paga.
 */
class PurchaseInvoiceRetention extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'tax_id',
        'tax_code',
        'tax_name',
        'tax_type',
        'base_amount',
        'rate',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
