<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reabre las cuentas que la migracion anterior cerro de mas.
 *
 * 2026_09_01_120000 hacia dos cosas: abrir las cuentas hoja (lo que se
 * necesitaba) y, "por coherencia", cerrar TODAS las que tienen hijos. Lo
 * segundo sobraba y rompio empresas reales: hay quien habilito a mano cuentas
 * de 4 digitos como 4135 o 1435 para usarlas como cuenta de venta o de
 * inventario de sus productos, y al cerrarlas la importacion de productos
 * empezo a rechazar todas las filas.
 *
 * Aqui se reabre lo que estaba EN USO: si un producto, un impuesto, un
 * asiento o cualquier otro registro ya apunta a esa cuenta, tiene que poder
 * recibir movimientos. Es la unica senal fiable de lo que alguien habilito a
 * proposito, porque el estado anterior no quedo guardado.
 *
 * Las que tienen hijos y nadie usa se quedan cerradas: ahi se postea en el
 * hijo, que es lo correcto.
 */
return new class extends Migration
{
    /** Cada columna que puede apuntar a una cuenta. */
    private const REFERENCIAS = [
        ['categories', 'default_cost_account_id'],
        ['categories', 'default_inventory_account_id'],
        ['categories', 'default_sale_account_id'],
        ['credit_debit_note_lines', 'account_id'],
        ['exogena_account_mappings', 'account_id'],
        ['expenses', 'expense_account_id'],
        ['expenses', 'payment_account_id'],
        ['fixed_assets', 'asset_account_id'],
        ['fixed_assets', 'depreciation_account_id'],
        ['fixed_assets', 'depreciation_expense_account_id'],
        ['inventory_adjustments', 'counterpart_account_id'],
        ['inventory_openings', 'counterpart_account_id'],
        ['journal_entry_lines', 'account_id'],
        ['order_taking_payments', 'account_id'],
        ['payment_methods', 'account_id'],
        ['payments', 'account_id'],
        ['payroll_account_mappings', 'account_id'],
        ['products', 'cost_account_id'],
        ['products', 'inventory_account_id'],
        ['products', 'sale_account_id'],
        ['purchase_invoice_lines', 'account_id'],
        ['sale_invoice_lines', 'account_id'],
        ['taxes', 'purchase_account_id'],
        ['taxes', 'sale_account_id'],
        ['third_parties', 'default_payable_account_id'],
        ['third_parties', 'default_receivable_account_id'],
    ];

    public function up(): void
    {
        $enUso = [];

        foreach (self::REFERENCIAS as [$tabla, $columna]) {
            if (! DB::getSchemaBuilder()->hasTable($tabla)) {
                continue;
            }

            $ids = DB::table($tabla)->whereNotNull($columna)->distinct()->pluck($columna)->all();
            $enUso = array_merge($enUso, $ids);
        }

        $enUso = array_values(array_unique(array_map('intval', $enUso)));

        if ($enUso === []) {
            return;
        }

        foreach (array_chunk($enUso, 500) as $lote) {
            DB::table('accounts')
                ->whereIn('id', $lote)
                ->where('accepts_movements', false)
                ->update(['accepts_movements' => true]);
        }
    }

    public function down(): void
    {
        // No se puede deshacer sin volver a romper lo que estaba en uso.
    }
};
