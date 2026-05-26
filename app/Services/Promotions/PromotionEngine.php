<?php

namespace App\Services\Promotions;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Support\PromotionsSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de aplicacion de promociones automaticas y cupones.
 *
 * Uso desde el POS:
 *
 *   $result = app(PromotionEngine::class)->evaluate(new CartContext(
 *       lines: $cartLines,           // array de CartLine
 *       customerId: $customer?->id,
 *       serviceMode: 'dine_in',      // dine_in | takeaway | delivery
 *       couponCode: $enteredCode,    // codigo manual ingresado por cajero
 *   ));
 *
 *   // result->lineDiscounts[lineIndex] = monto descontado en esa linea
 *   // result->orderDiscount = monto descontado adicional sobre el total
 *   // result->appliedPromotions[] = array de { promotion, discount, breakdown }
 *
 * Tras confirmar la venta, llamar:
 *
 *   $engine->recordUsages($result, $saleInvoiceId, $userId);
 *
 * que crea los PromotionUsage e incrementa promotion.usage_count.
 *
 * Reglas de orden:
 *   1. Filtra candidatas: active + vigencia + modo servicio + alcance
 *   2. Ordena por priority desc
 *   3. Si allow_stacking off: aplica solo la PRIMERA viable y descarta el resto
 *   4. Si allow_stacking on: aplica todas las que sean compatibles (stackable=true)
 */
class PromotionEngine
{
    public function evaluate(CartContext $cart): PromotionResult
    {
        $result = new PromotionResult($cart);

        if (! PromotionsSettings::moduleActive()) {
            return $result;
        }

        $candidates = $this->candidatePromotions($cart);
        if ($candidates->isEmpty()) {
            return $result;
        }

        $stackingEnabled = PromotionsSettings::isEnabled('allow_stacking');

        foreach ($candidates as $promotion) {
            $applied = $this->tryApply($promotion, $cart, $result);
            if (! $applied) continue;

            // Si NO se permite apilar, paramos al aplicar la primera viable
            if (! $stackingEnabled) {
                break;
            }
            // Si se permite apilar pero la promo no es stackable, tambien paramos
            if (! $promotion->stackable) {
                break;
            }
        }

        return $result;
    }

    /**
     * Despues de confirmar una venta, registra el uso de cada promocion
     * aplicada (para validar max_uses_per_customer y reportes).
     */
    public function recordUsages(PromotionResult $result, ?int $saleInvoiceId, int $userId): void
    {
        if (empty($result->appliedPromotions)) return;

        DB::transaction(function () use ($result, $saleInvoiceId, $userId) {
            foreach ($result->appliedPromotions as $applied) {
                /** @var Promotion $promo */
                $promo = $applied['promotion'];

                PromotionUsage::create([
                    'company_id' => $promo->company_id,
                    'promotion_id' => $promo->id,
                    'sale_invoice_id' => $saleInvoiceId,
                    'customer_third_party_id' => $result->context->customerId,
                    'user_id' => $userId,
                    'discount_applied' => $applied['discount'],
                    'applied_at' => now(),
                ]);

                // Incrementa el contador atomicamente
                Promotion::query()->whereKey($promo->id)->increment('usage_count');
            }
        });
    }

    /**
     * Filtra candidatas que cumplen condiciones basicas (active, vigencia,
     * modo servicio, codigo si lo requiere, limites de uso, umbrales).
     *
     * @return \Illuminate\Support\Collection<int, Promotion>
     */
    protected function candidatePromotions(CartContext $cart): \Illuminate\Support\Collection
    {
        $now = $cart->now ?? now();
        $cartQuantity = $cart->totalQuantity();
        $cartAmount = $cart->subtotal();

        $query = Promotion::query()
            ->active()
            ->currentlyValid($now)
            ->orderByDesc('priority')
            ->orderBy('id');

        // Codigo de cupon: si el usuario ingreso codigo, solo evaluamos
        // promociones con requires_code que matcheen el codigo (case-insensitive)
        // + las automaticas. Si NO ingreso codigo, solo automaticas.
        if ($cart->couponCode) {
            $code = strtoupper(trim($cart->couponCode));
            $query->where(function ($q) use ($code) {
                $q->where('requires_code', false)
                  ->orWhere(function ($q2) use ($code) {
                      $q2->where('requires_code', true)
                         ->whereRaw('UPPER(code) = ?', [$code]);
                  });
            });
        } else {
            $query->where('requires_code', false);
        }

        return $query->get()->filter(function (Promotion $p) use ($cart, $cartQuantity, $cartAmount, $now) {
            // Vigencia fina (dia + hora) — el scope solo valida fechas
            if (! $p->isCurrentlyValid($now)) return false;

            // Limites de uso
            if ($p->hasReachedTotalLimit()) return false;

            if ($p->max_uses_per_customer !== null && $cart->customerId) {
                $usedByThisCustomer = $p->usagesByCustomer($cart->customerId);
                if ($usedByThisCustomer >= $p->max_uses_per_customer) return false;
            }

            // Modo servicio (para restaurante)
            if ($cart->serviceMode === 'dine_in' && ! $p->applies_dine_in) return false;
            if ($cart->serviceMode === 'takeaway' && ! $p->applies_takeaway) return false;
            if ($cart->serviceMode === 'delivery' && ! $p->applies_delivery) return false;

            // Umbrales
            if ($p->min_quantity !== null && $cartQuantity < $p->min_quantity) return false;
            if ($p->min_amount !== null && $cartAmount < (float) $p->min_amount) return false;

            // Verificar que haya AL MENOS UN item del alcance en el carrito.
            // Sino la promo no aplica aunque cumpla los umbrales globales.
            $scopedLines = $this->linesInScope($p, $cart);
            if ($scopedLines->isEmpty()) return false;

            return true;
        })->values();
    }

    /**
     * Aplica una promocion al carrito mutando $result. Retorna true si
     * efectivamente aplico algun descuento.
     */
    protected function tryApply(Promotion $promotion, CartContext $cart, PromotionResult $result): bool
    {
        return match ($promotion->type) {
            Promotion::TYPE_PERCENTAGE => $this->applyPercentage($promotion, $cart, $result),
            Promotion::TYPE_FIXED_AMOUNT => $this->applyFixedAmount($promotion, $cart, $result),
            Promotion::TYPE_BOGO => $this->applyBogo($promotion, $cart, $result),
            Promotion::TYPE_VOLUME_TIER => $this->applyVolumeTier($promotion, $cart, $result),
            Promotion::TYPE_BUNDLE => $this->applyBundle($promotion, $cart, $result),
            default => false,
        };
    }

    /** Descuento % sobre items del alcance. */
    protected function applyPercentage(Promotion $p, CartContext $cart, PromotionResult $result): bool
    {
        $percent = (float) $p->discount_value / 100.0;
        if ($percent <= 0) return false;

        $totalDiscount = 0.0;
        foreach ($this->linesInScope($p, $cart) as $idx => $line) {
            $lineSubtotal = $line->quantity * $line->unitPrice;
            $discount = round($lineSubtotal * $percent, 2);
            $result->addLineDiscount($idx, $discount);
            $totalDiscount += $discount;
        }

        if ($totalDiscount <= 0) return false;
        $result->registerApplied($p, $totalDiscount);
        return true;
    }

    /**
     * Descuento de monto fijo distribuido proporcionalmente entre items del alcance.
     */
    protected function applyFixedAmount(Promotion $p, CartContext $cart, PromotionResult $result): bool
    {
        $discountTarget = (float) $p->discount_value;
        if ($discountTarget <= 0) return false;

        $scopedLines = $this->linesInScope($p, $cart);
        $scopedSubtotal = $scopedLines->sum(fn ($l) => $l->quantity * $l->unitPrice);
        if ($scopedSubtotal <= 0) return false;

        // No descontar mas de lo que cubre el subtotal del scope
        $effectiveDiscount = min($discountTarget, $scopedSubtotal);

        $totalDistributed = 0.0;
        $lineKeys = array_keys($scopedLines->all());
        $lastKey = end($lineKeys);

        foreach ($scopedLines as $idx => $line) {
            $lineSubtotal = $line->quantity * $line->unitPrice;
            if ($idx === $lastKey) {
                // El ultimo recibe el resto para evitar problemas de redondeo
                $share = $effectiveDiscount - $totalDistributed;
            } else {
                $share = round($effectiveDiscount * ($lineSubtotal / $scopedSubtotal), 2);
            }
            $result->addLineDiscount($idx, $share);
            $totalDistributed += $share;
        }

        $result->registerApplied($p, $effectiveDiscount);
        return true;
    }

    /**
     * BOGO: compra X paga Y. Las get_quantity unidades mas baratas son gratis.
     * Por cada bloque completo de (buy_qty + get_qty) cuenta una vez.
     */
    protected function applyBogo(Promotion $p, CartContext $cart, PromotionResult $result): bool
    {
        $buyQty = (int) ($p->discount_data['buy_quantity'] ?? 2);
        $getQty = (int) ($p->discount_data['get_quantity'] ?? 1);
        if ($buyQty < 2 || $getQty < 1) return false;
        $blockSize = $buyQty + $getQty;

        $scopedLines = $this->linesInScope($p, $cart);
        // Expandimos cada linea a unidades individuales para poder identificar
        // las N mas baratas a regalar.
        $units = [];
        foreach ($scopedLines as $idx => $line) {
            for ($i = 0; $i < $line->quantity; $i++) {
                $units[] = ['lineIdx' => $idx, 'price' => $line->unitPrice];
            }
        }
        $totalUnits = count($units);
        if ($totalUnits < $blockSize) return false;

        // Cuantos bloques completos hay
        $blocks = intdiv($totalUnits, $blockSize);
        $unitsToGiveFree = $blocks * $getQty;

        // Ordenamos por precio ascendente: las mas baratas son las gratis
        usort($units, fn ($a, $b) => $a['price'] <=> $b['price']);
        $freeUnits = array_slice($units, 0, $unitsToGiveFree);

        $totalDiscount = 0.0;
        // Agrupamos por linea para sumar descuentos por linea
        $byLine = [];
        foreach ($freeUnits as $u) {
            $byLine[$u['lineIdx']] = ($byLine[$u['lineIdx']] ?? 0) + $u['price'];
        }
        foreach ($byLine as $lineIdx => $discount) {
            $result->addLineDiscount($lineIdx, round($discount, 2));
            $totalDiscount += $discount;
        }

        if ($totalDiscount <= 0) return false;
        $result->registerApplied($p, round($totalDiscount, 2));
        return true;
    }

    /**
     * Volume tier: aplica el % del escalon segun la cantidad total de items
     * del alcance en el carrito.
     */
    protected function applyVolumeTier(Promotion $p, CartContext $cart, PromotionResult $result): bool
    {
        $tiers = $p->discount_data['tiers'] ?? [];
        if (empty($tiers)) return false;

        $scopedLines = $this->linesInScope($p, $cart);
        $totalQty = $scopedLines->sum('quantity');
        if ($totalQty <= 0) return false;

        // Encontrar el tier matching
        $matchedTier = null;
        foreach ($tiers as $tier) {
            $min = (int) ($tier['min'] ?? 0);
            $max = isset($tier['max']) && $tier['max'] !== null ? (int) $tier['max'] : PHP_INT_MAX;
            if ($totalQty >= $min && $totalQty <= $max) {
                $matchedTier = $tier;
                break;
            }
        }
        if (! $matchedTier) return false;

        $percent = (float) ($matchedTier['percent'] ?? 0) / 100.0;
        if ($percent <= 0) return false;

        $totalDiscount = 0.0;
        foreach ($scopedLines as $idx => $line) {
            $lineSubtotal = $line->quantity * $line->unitPrice;
            $discount = round($lineSubtotal * $percent, 2);
            $result->addLineDiscount($idx, $discount);
            $totalDiscount += $discount;
        }

        if ($totalDiscount <= 0) return false;
        $result->registerApplied($p, $totalDiscount);
        return true;
    }

    /**
     * Bundle: si el carrito contiene exactamente los items del combo
     * (en las cantidades requeridas), el subtotal de esos items se
     * reemplaza por bundle_price.
     */
    protected function applyBundle(Promotion $p, CartContext $cart, PromotionResult $result): bool
    {
        $items = $p->discount_data['items'] ?? [];
        $bundlePrice = (float) ($p->discount_data['bundle_price'] ?? 0);
        if (empty($items) || $bundlePrice <= 0) return false;

        // Indice de linea por product_id (suma cantidades si hay varias lineas del mismo producto)
        $cartByProduct = [];
        foreach ($cart->lines as $idx => $line) {
            $cartByProduct[$line->productId][] = ['lineIdx' => $idx, 'qty' => $line->quantity, 'price' => $line->unitPrice];
        }

        // Verificar que el carrito contenga los items requeridos
        $bundleLineIndices = [];
        $bundleSubtotal = 0.0;
        foreach ($items as $required) {
            $needed = (int) ($required['quantity'] ?? 1);
            $productId = (int) ($required['product_id'] ?? 0);
            if ($productId <= 0 || ! isset($cartByProduct[$productId])) return false;

            $availableQty = array_sum(array_column($cartByProduct[$productId], 'qty'));
            if ($availableQty < $needed) return false;

            // Tomamos las unidades necesarias y sumamos su subtotal
            $remaining = $needed;
            foreach ($cartByProduct[$productId] as $line) {
                if ($remaining <= 0) break;
                $take = min($remaining, $line['qty']);
                $bundleSubtotal += $take * $line['price'];
                $bundleLineIndices[$line['lineIdx']] = ($bundleLineIndices[$line['lineIdx']] ?? 0) + $take * $line['price'];
                $remaining -= $take;
            }
        }

        $discount = $bundleSubtotal - $bundlePrice;
        if ($discount <= 0) return false; // El combo no es ventaja para el cliente

        // Distribuir el descuento proporcionalmente entre las lineas del bundle
        foreach ($bundleLineIndices as $lineIdx => $contribution) {
            $share = round($discount * ($contribution / $bundleSubtotal), 2);
            $result->addLineDiscount($lineIdx, $share);
        }

        $result->registerApplied($p, round($discount, 2));
        return true;
    }

    /**
     * Devuelve solo las lineas del carrito que estan en el alcance de la
     * promocion (todos los productos / productos especificos / categorias).
     *
     * @return \Illuminate\Support\Collection<int, CartLine>
     */
    protected function linesInScope(Promotion $p, CartContext $cart): \Illuminate\Support\Collection
    {
        $collection = collect($cart->lines);

        return match ($p->scope) {
            Promotion::SCOPE_ALL => $collection,
            Promotion::SCOPE_PRODUCTS => $collection->filter(
                fn ($line) => in_array($line->productId, $p->scope_products ?? [], true),
            ),
            Promotion::SCOPE_CATEGORIES => $collection->filter(
                fn ($line) => $line->categoryId !== null
                    && in_array($line->categoryId, $p->scope_categories ?? [], true),
            ),
            default => collect(),
        };
    }
}
