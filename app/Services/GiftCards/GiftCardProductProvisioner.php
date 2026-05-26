<?php

namespace App\Services\GiftCards;

use App\Models\Company;
use App\Models\Product;

/**
 * Crea o resuelve el producto especial 'Tarjeta Regalo' que el POS usa
 * para vender gift cards con monto variable.
 *
 * Convencion: code = 'GIFTCARD'. El POS detecta este producto y abre un
 * modal pidiendo el monto y los datos del destinatario. No se inventaria
 * (track_inventory = false), no es comprable.
 *
 * Es idempotente — si ya existe, lo devuelve.
 */
class GiftCardProductProvisioner
{
    public const PRODUCT_CODE = 'GIFTCARD';

    public function provision(Company $company): Product
    {
        return Product::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'code' => self::PRODUCT_CODE],
            [
                'name' => 'Tarjeta Regalo',
                'description' => 'Bono prepagado. El cajero ingresa el monto al venderlo. Se entrega al cliente como tarjeta con codigo para redimir en compras futuras.',
                'type' => 'service',
                'default_sale_price' => 0,
                'unit_of_measure' => 'und',
                'is_purchasable' => false,
                'is_sellable' => true,
                'track_inventory' => false,
                'active' => true,
            ],
        );
    }

    /**
     * Busca el producto Gift Card de la empresa actual sin crearlo si no existe.
     */
    public static function find(int $companyId): ?Product
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->where('code', self::PRODUCT_CODE)
            ->first();
    }

    /**
     * True si el producto recibido es el especial Gift Card.
     */
    public static function isGiftCardProduct(Product $product): bool
    {
        return $product->code === self::PRODUCT_CODE;
    }
}
