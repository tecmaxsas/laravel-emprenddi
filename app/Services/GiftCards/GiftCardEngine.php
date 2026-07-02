<?php

namespace App\Services\GiftCards;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Support\GiftCardsSettings;
use Illuminate\Support\Facades\DB;

/**
 * Servicio thin que valida codigos y orquesta emision/redencion de gift
 * cards. Las mutaciones de saldo viven en GiftCard::charge/refund/cancel
 * (atomicas + logueadas en transactions); este servicio solo agrega
 * validaciones de modulo y reglas de feature.
 *
 * Uso desde POS:
 *
 *   $engine = app(GiftCardEngine::class);
 *
 *   // Validar antes de aplicar como pago
 *   $card = $engine->findRedeemable($code);  // null si no existe/no es valida
 *   if (! $card) { /* error * / }
 *
 *   // Aplicar como medio de pago (despues de confirmar venta)
 *   $engine->redeem($card, $amountToCharge, $saleInvoiceId, $userId);
 *
 *   // Emitir nueva via POS al vender producto-gift-card
 *   $newCard = $engine->issue(
 *       initialBalance: $amount,
 *       userId: auth()->id(),
 *       saleInvoiceId: $sale->id,
 *       recipientName: $data['recipient'] ?? null,
 *   );
 */
class GiftCardEngine
{
    /**
     * Busca una tarjeta por codigo (case-insensitive, trim) que sea
     * redimible (active, con saldo, no expirada). Solo busca en la
     * empresa actual del usuario.
     */
    public function findRedeemable(string $code): ?GiftCard
    {
        if (! GiftCardsSettings::moduleActive()) {
            return null;
        }

        $normalized = strtoupper(trim($code));
        if ($normalized === '') return null;

        // Scoping explicito: el redeem por codigo es entrada de usuario,
        // no confiar en el global scope inerte cuando CurrentCompany no
        // esta hidratado. Un codigo colision entre empresas nunca debe
        // permitir redimir una gift card de otra empresa.
        return GiftCard::query()
            ->where('company_id', auth()->user()?->company_id)
            ->redeemable()
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->first();
    }

    /**
     * Redime un monto de la tarjeta. Si allow_partial_redemption esta off
     * y el monto no consume todo el saldo, lanza excepcion.
     */
    public function redeem(GiftCard $card, float $amount, ?int $saleInvoiceId, int $userId): GiftCardTransaction
    {
        if (! GiftCardsSettings::moduleActive()) {
            throw new \DomainException('El modulo de gift cards no esta activo.');
        }

        if (! GiftCardsSettings::isEnabled('allow_partial_redemption')) {
            if ($amount < (float) $card->current_balance) {
                throw new \DomainException(
                    "Redencion parcial no permitida. Debes redimir el saldo completo (\${$card->current_balance})."
                );
            }
        }

        return $card->charge($amount, $userId, $saleInvoiceId, 'Redimida en POS');
    }

    /**
     * Emite una nueva gift card. Tipicamente se llama desde el flow de
     * venta de un producto-giftcard en POS. El codigo se genera automatico.
     *
     * @param  array{
     *   recipient_name?: string,
     *   recipient_email?: string,
     *   recipient_phone?: string,
     *   sender_name?: string,
     *   expires_at?: ?\DateTimeInterface,
     *   notes?: string,
     * }  $meta
     */
    public function issue(
        float $initialBalance,
        int $userId,
        ?int $saleInvoiceId = null,
        array $meta = [],
    ): GiftCard {
        if (! GiftCardsSettings::moduleActive()) {
            throw new \DomainException('El modulo de gift cards no esta activo.');
        }
        if ($initialBalance <= 0) {
            throw new \InvalidArgumentException('El saldo inicial debe ser positivo.');
        }

        $companyId = auth()->user()->company_id ?? null;
        if (! $companyId) {
            throw new \DomainException('Usuario sin empresa asociada.');
        }

        // Expiracion default segun settings (si no se proveyo expires_at)
        $expiresAt = $meta['expires_at'] ?? null;
        if ($expiresAt === null) {
            $months = (int) GiftCardsSettings::get('default_expiry_months');
            if ($months > 0) {
                $expiresAt = now()->addMonths($months);
            }
        }

        return DB::transaction(function () use ($companyId, $initialBalance, $userId, $saleInvoiceId, $meta, $expiresAt) {
            $card = GiftCard::create([
                'company_id' => $companyId,
                'code' => GiftCard::generateCode($companyId),
                'initial_balance' => $initialBalance,
                'current_balance' => $initialBalance,
                'currency' => 'COP',
                'status' => GiftCard::STATUS_ACTIVE,
                'issued_at' => now(),
                'issued_by_user_id' => $userId,
                'issued_via_sale_invoice_id' => $saleInvoiceId,
                'expires_at' => $expiresAt,
                'recipient_name' => $meta['recipient_name'] ?? null,
                'recipient_email' => $meta['recipient_email'] ?? null,
                'recipient_phone' => $meta['recipient_phone'] ?? null,
                'sender_name' => $meta['sender_name'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ]);

            $card->transactions()->create([
                'company_id' => $companyId,
                'type' => GiftCardTransaction::TYPE_ISSUE,
                'amount' => $initialBalance,
                'balance_after' => $initialBalance,
                'sale_invoice_id' => $saleInvoiceId,
                'user_id' => $userId,
                'notes' => $saleInvoiceId ? 'Emitida via POS' : 'Emitida desde admin',
            ]);

            return $card;
        });
    }
}
