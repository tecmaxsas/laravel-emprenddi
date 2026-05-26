<?php

namespace App\Services\Promotions;

use Carbon\Carbon;

/**
 * Snapshot del carrito + metadata necesaria para evaluar promociones.
 *
 * Lo construye el POS al pedir una evaluacion al PromotionEngine, NO
 * accede a Eloquent — solo data plana. Esto permite probar el motor
 * sin DB y hace explicito que el engine no muta el cart original.
 */
class CartContext
{
    /**
     * @param  CartLine[]  $lines      lineas del carrito (la posicion en el array
     *                                  es lineIdx — usado por addLineDiscount)
     * @param  ?int        $customerId tercero cliente (para max_uses_per_customer)
     * @param  string      $serviceMode dine_in | takeaway | delivery
     * @param  ?string     $couponCode codigo manual del cliente
     * @param  ?Carbon     $now        para tests; default = now()
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?int $customerId = null,
        public readonly string $serviceMode = 'dine_in',
        public readonly ?string $couponCode = null,
        public readonly ?Carbon $now = null,
    ) {
    }

    /** Cantidad total de unidades en el carrito. */
    public function totalQuantity(): int
    {
        return array_sum(array_map(fn (CartLine $l) => $l->quantity, $this->lines));
    }

    /** Subtotal del carrito (suma de quantity * unitPrice antes de descuentos). */
    public function subtotal(): float
    {
        $sum = 0.0;
        foreach ($this->lines as $line) {
            $sum += $line->quantity * $line->unitPrice;
        }
        return $sum;
    }
}
