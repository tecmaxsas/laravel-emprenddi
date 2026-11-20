<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * El saldo actual es el del ULTIMO MOVIMIENTO REGISTRADO (id), no el de fecha
 * mayor.
 *
 * Cada movimiento calcula su saldo sobre el anterior en el momento de
 * guardarse, asi que la cadena de saldos va en orden de registro. Ordenar por
 * fecha rompia eso: una compra registrada con la fecha de la factura del
 * proveedor (dias atras) quedaba detras de ventas posteriores y su entrada no
 * se veia en la existencia. Lo mismo con las devoluciones/anulaciones, que
 * guardan fecha con hora mientras el resto guarda solo el dia.
 *
 * InventoryEngine.php es de root y no se puede editar; esta subclase se
 * registra en AppServiceProvider para que app(InventoryEngine::class) la use.
 * Si algun dia se puede editar el original, mover estos cambios alla y borrar
 * esta clase.
 */
class InventoryEngineOrdenado extends InventoryEngine
{
    public function lastMovement(int $productId, int $locationId): ?InventoryMovement
    {
        $companyId = app(CurrentCompany::class)->id()
            ?? auth()->user()?->company_id;

        return InventoryMovement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->orderBy('id', 'desc')
            ->first();
    }

    public function stockForProducts(array $productIds, int $locationId): array
    {
        if ($productIds === []) {
            return [];
        }

        $companyId = app(CurrentCompany::class)->id()
            ?? auth()->user()?->company_id;

        if (! $companyId) {
            return [];
        }

        $stock = DB::table('inventory_movements')
            ->selectRaw('DISTINCT ON (product_id) product_id, balance_quantity_after')
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderBy('id', 'desc')
            ->pluck('balance_quantity_after', 'product_id')
            ->mapWithKeys(fn ($s, $id) => [(int) $id => (float) $s])
            ->all();

        foreach ($productIds as $id) {
            $stock[(int) $id] ??= 0.0;
        }

        return $stock;
    }
}
