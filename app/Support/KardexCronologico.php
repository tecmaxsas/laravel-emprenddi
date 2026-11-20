<?php

namespace App\Support;

use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de un (producto, sede) en orden cronologico, con el saldo
 * calculado en ESE orden.
 *
 * balance_quantity_after se encadena en orden de registro (id): una compra
 * registrada hoy con la fecha de la factura del proveedor lleva el saldo de
 * hoy, y en un kardex ordenado por fecha la columna saltaba. Aqui el saldo es
 * la suma acumulada por dia y, dentro del dia, por orden de registro (date::date
 * porque las ediciones guardan hora y el resto solo el dia). Se calcula sobre
 * TODOS los movimientos del par; los filtros de fecha/tipo se aplican despues,
 * para que el saldo de la primera fila visible arrastre lo anterior.
 *
 * saldo_valor = saldo × costo promedio vigente al registrar el movimiento.
 */
class KardexCronologico
{
    public const ORDEN = 'date::date, id';

    public static function query(int $companyId, int $productId, int $locationId): Builder
    {
        $base = DB::table('inventory_movements')
            ->selectRaw('inventory_movements.*, sum(quantity) over (order by '.self::ORDEN.') as saldo_cronologico')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId);

        return InventoryMovement::query()
            ->fromSub($base, 'inventory_movements')
            ->select('inventory_movements.*')
            ->orderByRaw(self::ORDEN);
    }

    public static function saldo(InventoryMovement $m): float
    {
        return (float) ($m->saldo_cronologico ?? $m->balance_quantity_after);
    }

    public static function saldoValor(InventoryMovement $m): float
    {
        return round(self::saldo($m) * (float) $m->balance_cost_after, 2);
    }
}
