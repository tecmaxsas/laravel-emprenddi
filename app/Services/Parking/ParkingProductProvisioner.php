<?php

namespace App\Services\Parking;

use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use App\Support\DefaultAccounts;
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
                // `withoutGlobalScopes()` tambien apaga el filtro de
                // borrados, asi que aqui puede llegar un producto en la
                // papelera. Devolverlo tal cual era el error: la factura
                // guardaba su id y al releerla con los scopes normales el
                // producto salia null —«Call to a member function isKit() on
                // null» en mitad de un cobro—. Si alguien lo borro de la
                // lista, se restaura: sin el no se puede facturar el parqueo.
                if ($product->trashed()) {
                    $product->restore();
                }

                // Y tiene que poder venderse. Desactivado no reventaba, pero
                // desaparecia de las busquedas y confundia igual.
                if (! $product->active || ! $product->is_sellable) {
                    $product->update(['active' => true, 'is_sellable' => true]);
                }

                // Backfill de sale_account_id en productos creados antes del
                // fix (cuando quedaban en null y caia a 4135 mercancias).
                if (! $product->sale_account_id) {
                    $accountId = DefaultAccounts::porCodigo('4145', (int) $company->id);
                    if ($accountId) {
                        $product->update(['sale_account_id' => $accountId]);
                    }
                }

                return $product;
            }

            // IVA 19% por defecto si existe en el catalogo. Sin tax si no.
            $tax = Tax::query()
                ->where('company_id', $company->id)
                ->where('type', 'vat')
                ->where('rate', 19)
                ->where('is_active', true)
                ->first();

            // Cuenta de ingreso correcta segun PUC colombiano:
            // 4145 — Transporte, almacenamiento y comunicaciones (incluye parqueaderos)
            // Si no esta en el PUC de la empresa, deja null y SaleInvoiceEngine
            // caera al fallback 4135 (mercancias) — el admin puede ajustarla.
            $saleAccountId = DefaultAccounts::porCodigo('4145', (int) $company->id);

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
                'sale_account_id' => $saleAccountId,
                'active' => true,
            ]);
        });
    }

    public static function isParkingProduct(?Product $product): bool
    {
        return $product?->code === self::CODE;
    }
}
