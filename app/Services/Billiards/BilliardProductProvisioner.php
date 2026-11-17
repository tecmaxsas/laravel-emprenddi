<?php

namespace App\Services\Billiards;

use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use App\Support\DefaultAccounts;
use Illuminate\Support\Facades\DB;

/**
 * Asegura el producto "Alquiler de mesa de billar" de la empresa: es el que
 * usa la linea de tiempo de cada factura.
 *
 * Idempotente. Si alguien lo borro de la lista de productos, se restaura en
 * vez de devolver un registro en papelera: esa fue exactamente la caida del
 * parqueadero en produccion —la factura guardaba el id, al releerla con los
 * scopes normales el producto salia null y el cobro reventaba con «Call to a
 * member function isKit() on null» con el cliente en el mostrador—.
 */
class BilliardProductProvisioner
{
    public const CODE = 'BILLAR-MESA';

    public function ensure(Company $company): Product
    {
        return DB::transaction(function () use ($company) {
            $producto = Product::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', self::CODE)
                ->first();

            if ($producto) {
                if ($producto->trashed()) {
                    $producto->restore();
                }

                if (! $producto->active || ! $producto->is_sellable) {
                    $producto->update(['active' => true, 'is_sellable' => true]);
                }

                if (! $producto->sale_account_id) {
                    $cuenta = $this->cuentaDeIngreso($company);

                    if ($cuenta) {
                        $producto->update(['sale_account_id' => $cuenta]);
                    }
                }

                return $producto;
            }

            $iva = Tax::query()
                ->where('company_id', $company->id)
                ->where('type', 'vat')
                ->where('rate', 19)
                ->where('is_active', true)
                ->first();

            return Product::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => self::CODE,
                'name' => 'Alquiler de mesa de billar',
                'description' => 'Cobro por tiempo de mesa. La línea se completa con la mesa y el tiempo jugado en cada cuenta.',
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

    /**
     * 4170 — Otras actividades de servicios. Es donde el PUC colombiano deja
     * el esparcimiento: un billar no vende mercancia (4135) ni transporte
     * (4145). Si la empresa no tiene esa cuenta abierta, queda null y
     * SaleInvoiceEngine cae al 4135; el contador puede corregirlo despues
     * desde la ficha del producto.
     */
    protected function cuentaDeIngreso(Company $company): ?int
    {
        return DefaultAccounts::porCodigo('4170', (int) $company->id);
    }

    public static function isBilliardProduct(?Product $product): bool
    {
        return $product?->code === self::CODE;
    }
}
