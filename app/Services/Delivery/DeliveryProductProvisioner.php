<?php

namespace App\Services\Delivery;

use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use App\Support\DefaultAccounts;
use Illuminate\Support\Facades\DB;

/**
 * El producto con el que se cobra el domicilio en una factura de venta.
 *
 * POR QUE UN PRODUCTO Y NO UNA COLUMNA.
 *
 * El domicilio lo paga el cliente, asi que tiene que ir EN la factura: lleva
 * IVA, entra en la base gravable, viaja a la DIAN y se contabiliza contra una
 * cuenta de ingreso. Una columna `delivery_fee` colgada del total obliga a
 * acordarse de ella en cada uno de esos sitios, y el dia que alguien agregue
 * un reporte nuevo se le va a olvidar.
 *
 * Como linea de factura no hay nada que acordarse: los totales, los impuestos,
 * el asiento, el tiquete, el arqueo y el XML de la DIAN ya saben sumar lineas.
 *
 * Esto no es teoria: el modulo de restaurante guarda el domicilio en
 * `orders.delivery_fee` y esa plata NO llega a la factura. Se cobra y no se
 * factura. Aqui no se repite.
 *
 * Es el mismo patron de parqueadero y hotel, y por eso entra en
 * `ProductosInternos`: no va en la vitrina del catalogo publico.
 */
class DeliveryProductProvisioner
{
    public const CODE = 'DOMICILIO';

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

            $tax = Tax::query()
                ->where('company_id', $company->id)
                ->where('type', 'vat')
                ->where('rate', 19)
                ->where('is_active', true)
                ->first();

            // 4145 — Transporte, almacenamiento y comunicaciones. Llevar un
            // pedido a una direccion es transporte, no venta de mercancia:
            // mandarlo a 4135 inflaria las ventas de mercancia con plata que
            // es de otro negocio.
            $saleAccountId = DefaultAccounts::porCodigo('4145', (int) $company->id);

            return Product::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => self::CODE,
                'name' => 'Domicilio',
                'description' => 'Cobro por entrega a domicilio. El valor se fija en cada venta.',
                'type' => 'service',
                'unit_of_measure' => 'service',
                'track_inventory' => false,
                'tracks_serials' => false,
                'is_purchasable' => false,
                'is_sellable' => true,
                // Cero a proposito: el valor real lo pone el cajero o sale del
                // ajuste de la empresa. Un precio aqui seria un tercer sitio
                // donde vive la misma cifra.
                'default_sale_price' => 0,
                'sale_price_includes_tax' => false,
                'default_sale_tax_id' => $tax?->id,
                'sale_account_id' => $saleAccountId,
                'active' => true,
            ]);
        });
    }

    public static function esProductoDeDomicilio(?Product $product): bool
    {
        return $product?->code === self::CODE;
    }
}
