<?php

namespace App\Services\Promotions;

use App\Models\Promotion;

/**
 * Resultado de evaluar el carrito contra las promociones disponibles.
 *
 * - lineDiscounts[lineIdx] = monto descontado sumado en esa linea
 *   (puede venir de varias promos si stacking esta activo)
 * - orderDiscount = descuento adicional aplicado al total (reservado
 *   para promos a nivel orden — hoy todas distribuyen por linea)
 * - appliedPromotions[] = log de cada promo que se aplico (para
 *   mostrar al cajero, imprimir en tirilla, y registrar via
 *   recordUsages despues de confirmar la venta)
 */
class PromotionResult
{
    /** @var array<int, float> lineIdx → descuento aplicado */
    public array $lineDiscounts = [];

    public float $orderDiscount = 0.0;

    /** @var array<int, array{promotion: Promotion, discount: float}> */
    public array $appliedPromotions = [];

    public function __construct(public readonly CartContext $context)
    {
    }

    public function addLineDiscount(int $lineIdx, float $amount): void
    {
        if ($amount <= 0) return;
        $this->lineDiscounts[$lineIdx] = ($this->lineDiscounts[$lineIdx] ?? 0) + $amount;
    }

    public function registerApplied(Promotion $promotion, float $discount): void
    {
        $this->appliedPromotions[] = [
            'promotion' => $promotion,
            'discount' => $discount,
        ];
    }

    /** Monto total descontado en la venta (lineas + orden). */
    public function totalDiscount(): float
    {
        return array_sum($this->lineDiscounts) + $this->orderDiscount;
    }

    /** Indica si alguna promocion aplico. */
    public function hasDiscounts(): bool
    {
        return ! empty($this->appliedPromotions);
    }

    /**
     * Resumen para imprimir en tirilla / mostrar al cajero.
     * Devuelve array<['name' => string, 'discount' => float, 'code' => ?string]>
     */
    public function summary(): array
    {
        return array_map(function ($applied) {
            /** @var Promotion $p */
            $p = $applied['promotion'];
            return [
                'name' => $p->name,
                'discount' => $applied['discount'],
                'code' => $p->code,
            ];
        }, $this->appliedPromotions);
    }
}
