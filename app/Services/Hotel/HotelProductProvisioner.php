<?php

namespace App\Services\Hotel;

use App\Models\Account;
use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;

/**
 * Asegura que exista el producto «Alojamiento» de la empresa.
 *
 * Es el producto con el que se factura cada noche. Idempotente: si ya está,
 * lo devuelve; si no, lo crea con valores sanos que el administrador puede
 * ajustar después (la cuenta de ingreso, el IVA).
 *
 * No lleva precio porque el precio no es suyo: cada noche se cobra a la
 * tarifa del tipo de habitación, y eso vive en hotel_room_types.
 */
class HotelProductProvisioner
{
    public const CODE = 'HOTEL-ALOJ';

    public function ensure(Company $company): Product
    {
        return DB::transaction(function () use ($company) {
            $producto = Product::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', self::CODE)
                ->first();

            if ($producto) {
                if (! $producto->sale_account_id) {
                    $cuenta = $this->cuentaDeIngreso($company);

                    if ($cuenta) {
                        $producto->update(['sale_account_id' => $cuenta]);
                    }
                }

                return $producto;
            }

            // IVA 19% si está en el catálogo de la empresa. El hospedaje en
            // Colombia es gravado al 19%; la exención del turista extranjero
            // no residente se maneja por estadía, no por producto, porque
            // depende de quién duerme y no de qué se vende.
            $iva = Tax::query()
                ->where('company_id', $company->id)
                ->where('type', 'vat')
                ->where('rate', 19)
                ->where('is_active', true)
                ->first();

            return Product::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => self::CODE,
                'name' => 'Alojamiento',
                'description' => 'Servicio de hospedaje. La descripción de cada noche se completa '
                    .'con la habitación y la fecha al facturar el folio.',
                'type' => 'service',
                'unit_of_measure' => 'service',
                'track_inventory' => false,
                'tracks_serials' => false,
                'is_purchasable' => false,
                'is_sellable' => true,
                'default_sale_price' => 0,
                'sale_price_includes_tax' => false,
                'default_sale_tax_id' => $iva?->id,
                'sale_account_id' => $this->cuentaDeIngreso($company),
                'active' => true,
            ]);
        });
    }

    public static function esProductoDeAlojamiento(?Product $producto): bool
    {
        return $producto?->code === self::CODE;
    }

    /**
     * 4140 — Hoteles y restaurantes, la cuenta de ingreso del PUC colombiano
     * para este negocio. Si la empresa no la tiene en su plan, queda null y
     * SaleInvoiceEngine cae a 4135 (mercancías); el administrador puede
     * corregirla en la ficha del producto.
     */
    protected function cuentaDeIngreso(Company $company): ?int
    {
        return Account::query()
            ->where('company_id', $company->id)
            ->where('code', '4140')
            ->where('accepts_movements', true)
            ->where('active', true)
            ->value('id');
    }
}
