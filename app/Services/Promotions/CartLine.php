<?php

namespace App\Services\Promotions;

/**
 * Linea del carrito vista por el motor de promociones. Solo los datos
 * necesarios para evaluar (no incluye modificadores, impuestos, ni
 * descripcion). El POS la construye antes de llamar al engine y la
 * mapea de vuelta a sus propias estructuras despues.
 */
class CartLine
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $categoryId,
        public readonly int $quantity,
        public readonly float $unitPrice,
        // Identificador opaco del POS (cart_uuid de la linea, etc.) — pasa
        // intacto en el resultado para que el POS sepa a qué línea mapear.
        public readonly ?string $reference = null,
    ) {
    }
}
