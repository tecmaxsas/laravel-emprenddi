<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegisterSession extends Model
{
    use BelongsToCompany;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id',
        'location_id',
        'cashier_user_id',
        'closed_by_user_id',
        'status',
        'opened_at',
        'closed_at',
        'opening_amount',
        'closing_expected',
        'closing_counted',
        'closing_difference',
        'total_sales',
        'invoice_count',
        'payment_breakdown',
        'opening_notes',
        'closing_notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_amount' => 'decimal:2',
            'closing_expected' => 'decimal:2',
            'closing_counted' => 'decimal:2',
            'closing_difference' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'invoice_count' => 'integer',
            'payment_breakdown' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function saleInvoices(): HasMany
    {
        return $this->hasMany(SaleInvoice::class);
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Calcula los totales agregando las facturas posteadas de la sesión.
     *
     * Devuelve:
     *   total_sales: suma de net_payable de facturas posted
     *   invoice_count: cuántas
     *   payment_breakdown: ['cash'=>X, 'credit_card'=>Y, ...]
     *   cash_received: suma específica de pagos en efectivo
     *   closing_expected: opening_amount + cash_received
     */
    public function computeRunningTotals(): array
    {
        $invoices = $this->saleInvoices()
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->with('payments')
            ->get();

        $totalSales = (float) $invoices->sum('net_payable');
        $invoiceCount = $invoices->count();
        $breakdown = [];
        $cashReceived = 0;

        foreach ($invoices as $inv) {
            foreach ($inv->payments as $pay) {
                $method = $pay->payment_method;
                $amount = (float) $pay->amount;
                $breakdown[$method] = ($breakdown[$method] ?? 0) + $amount;
                if ($method === 'cash') {
                    $cashReceived += $amount;
                }
            }
        }

        return [
            'total_sales' => round($totalSales, 2),
            'invoice_count' => $invoiceCount,
            'payment_breakdown' => $breakdown,
            'cash_received' => round($cashReceived, 2),
            'closing_expected' => round((float) $this->opening_amount + $cashReceived, 2),
        ];
    }
}
