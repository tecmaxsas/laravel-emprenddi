<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Igual que CatalogProducts, pero el saldo de cada (producto, sede) es el del
 * ultimo movimiento registrado (id), como en InventoryEngineOrdenado.
 * CatalogProducts.php es de root; se registra en AppServiceProvider.
 */
class CatalogProductsOrdenado extends CatalogProducts
{
    public function existencias(ProductCatalog $catalogo, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $ultimos = DB::table('inventory_movements')
            ->selectRaw('distinct on (product_id, location_id) product_id, balance_quantity_after')
            ->where('company_id', $catalogo->company_id)
            ->whereIn('product_id', $productIds)
            ->orderByRaw('product_id, location_id, id desc');

        return DB::query()
            ->fromSub($ultimos, 'u')
            ->selectRaw('product_id, sum(balance_quantity_after) as saldo')
            ->groupBy('product_id')
            ->pluck('saldo', 'product_id')
            ->map(fn ($s) => (float) $s)
            ->all();
    }
}
