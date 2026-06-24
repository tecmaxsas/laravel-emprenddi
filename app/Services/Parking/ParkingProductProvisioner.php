<?php

namespace App\Services\Parking;

use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;

/**
 * Asegura que exista el producto "Servicio de Parqueadero" para la empresa.
 * Es el producto que las lineas de SaleInvoice usan al facturar cada salida.
 * Idempotente: si ya existe lo retorna; si no, lo crea con defaults sanos.
 *
 * El usuario admin puede editarlo luego para cambiar la cuenta de venta,
 * el IVA, el precio (que aqui no aplica porque se recalcula sesion por
 * sesion), etc.
 */
class ParkingProductProvisioner
{
    public const CODE = 'PARKING-SVC';

    public function ensure(Company $company): Product
    {
        return DB::transaction(function () use ($company) {
            $product = Product::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', self::CODE)
                ->first();
            if ($product) {
                return $product;
            }

            // IVA 19% por defecto si existe en el catalogo. Sin tax si no.
            $tax = Tax::query()
                ->where('company_id', $company->id)
                ->where('type', 'vat')
                ->where('rate', 19)
                ->where('is_active', true)
                ->first();

            return Product::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => self::CODE,
                'name' => 'Servicio de Parqueadero',
                'description' => 'Cobro por uso de parqueadero (la línea se completa con placa y tiempo en cada sesión).',
                'type' => 'service',
                'unit_of_measure' => 'service',
                'track_inventory' => false,
                'tracks_serials' => false,
                'is_purchasable' => false,
                'is_sellable' => true,
                'default_sale_price' => 0,
                'sale_price_includes_tax' => false,
                'default_sale_tax_id' => $tax?->id,
                'active' => true,
            ]);
        });
    }

    public static function isParkingProduct(?Product $product): bool
    {
        return $product?->code === self::CODE;
    }
}
