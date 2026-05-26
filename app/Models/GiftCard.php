<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tarjeta regalo / bono. Tiene un codigo unico por empresa y un saldo
 * que se va consumiendo en redenciones (POS, restaurante, e-commerce).
 *
 * Vida:
 *   1. Emitida (issued_at, saldo = initial)
 *   2. Redimida parcial o total (saldo baja)
 *   3. Cuando current_balance llega a 0 → status = fully_redeemed
 *   4. Si pasa expires_at antes de redimirse total → expired
 *   5. Admin puede cancelar manualmente → cancelled
 *
 * Toda mutacion de saldo debe pasar por charge() / refund() para que
 * quede traza en gift_card_transactions.
 */
class GiftCard extends Model
{
    use HasFactory, BelongsToCompany;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_FULLY_REDEEMED = 'fully_redeemed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'code',
        'initial_balance', 'current_balance', 'currency',
        'status',
        'issued_at', 'issued_by_user_id', 'issued_via_sale_invoice_id',
        'expires_at', 'last_redeemed_at',
        'recipient_name', 'recipient_email', 'recipient_phone',
        'sender_name', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_redeemed_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class)->orderByDesc('created_at');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function issuedViaSale(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'issued_via_sale_invoice_id');
    }

    // ====================================================================
    // Scopes
    // ====================================================================

    public function scopeRedeemable(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->where('current_balance', '>', 0)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });
    }

    // ====================================================================
    // Generacion de codigo y emision
    // ====================================================================

    /**
     * Genera un codigo unico legible para gift card. Formato: GC-XXXXX-XXXXX
     * donde X son caracteres ambiguos-friendly (sin 0/O/I/1 para evitar
     * confusion al dictar/teclear).
     */
    public static function generateCode(int $companyId): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'GC-' . self::randomChunk($chars, 5) . '-' . self::randomChunk($chars, 5);
        } while (self::where('company_id', $companyId)->where('code', $code)->exists());
        return $code;
    }

    private static function randomChunk(string $alphabet, int $length): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    // ====================================================================
    // Mutaciones de saldo — siempre con transaccion + log en transactions
    // ====================================================================

    /**
     * Redime un monto de la tarjeta. Devuelve true si la operacion fue
     * exitosa. Lanza InvalidArgumentException si el monto excede el saldo
     * disponible o la tarjeta no es redimible.
     */
    public function charge(float $amount, int $userId, ?int $saleInvoiceId = null, ?string $notes = null): GiftCardTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('El monto a redimir debe ser positivo.');
        }
        if (! $this->isRedeemable()) {
            throw new \DomainException("La gift card {$this->code} no es redimible (status: {$this->status}).");
        }
        if ($amount > (float) $this->current_balance) {
            throw new \DomainException("Saldo insuficiente: \${$this->current_balance} disponible, se intento redimir \${$amount}.");
        }

        return DB::transaction(function () use ($amount, $userId, $saleInvoiceId, $notes) {
            $this->current_balance = (float) $this->current_balance - $amount;
            $this->last_redeemed_at = now();
            if ((float) $this->current_balance == 0.0) {
                $this->status = self::STATUS_FULLY_REDEEMED;
            }
            $this->save();

            return $this->transactions()->create([
                'company_id' => $this->company_id,
                'type' => GiftCardTransaction::TYPE_REDEEM,
                'amount' => -$amount,
                'balance_after' => $this->current_balance,
                'sale_invoice_id' => $saleInvoiceId,
                'user_id' => $userId,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Devuelve saldo a la tarjeta (refund por devolucion de la venta donde
     * se uso). Reactiva la tarjeta si estaba fully_redeemed.
     */
    public function refund(float $amount, int $userId, ?int $saleInvoiceId = null, ?string $notes = null): GiftCardTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('El monto a devolver debe ser positivo.');
        }

        return DB::transaction(function () use ($amount, $userId, $saleInvoiceId, $notes) {
            $this->current_balance = (float) $this->current_balance + $amount;
            if ($this->status === self::STATUS_FULLY_REDEEMED) {
                $this->status = self::STATUS_ACTIVE;
            }
            $this->save();

            return $this->transactions()->create([
                'company_id' => $this->company_id,
                'type' => GiftCardTransaction::TYPE_REFUND,
                'amount' => $amount,
                'balance_after' => $this->current_balance,
                'sale_invoice_id' => $saleInvoiceId,
                'user_id' => $userId,
                'notes' => $notes,
            ]);
        });
    }

    /** Cancela la tarjeta dejando saldo en 0 (irrecuperable). */
    public function cancel(int $userId, ?string $reason = null): GiftCardTransaction
    {
        return DB::transaction(function () use ($userId, $reason) {
            $previousBalance = (float) $this->current_balance;
            $this->current_balance = 0;
            $this->status = self::STATUS_CANCELLED;
            $this->save();

            return $this->transactions()->create([
                'company_id' => $this->company_id,
                'type' => GiftCardTransaction::TYPE_CANCEL,
                'amount' => -$previousBalance,
                'balance_after' => 0,
                'user_id' => $userId,
                'notes' => $reason,
            ]);
        });
    }

    // ====================================================================
    // Estado
    // ====================================================================

    public function isRedeemable(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) return false;
        if ((float) $this->current_balance <= 0) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
