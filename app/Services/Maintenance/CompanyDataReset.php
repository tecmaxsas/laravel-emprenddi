<?php

namespace App\Services\Maintenance;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Borra los datos TRANSACCIONALES de una empresa, conservando la
 * configuración (PUC, impuestos, sedes, usuarios, terceros, métodos de
 * pago, plantillas, catálogos del restaurante, etc.).
 *
 * Pensado para clientes en periodo de prueba que después de "jugar" con
 * la plataforma quieren empezar limpio sin perder lo configurado.
 *
 * Dos modos:
 *  - resetTransactional()                    → además borra productos y product_locations
 *  - resetTransactionalKeepingProducts()     → conserva products y product_locations (sin stock, porque inventory_movements queda vacío)
 *
 * Se ejecuta por SQL directo (no Eloquent) por dos razones:
 *  1. Velocidad: una empresa con 50k movimientos puede tardar mucho con Eloquent.
 *  2. Seguridad: evita disparar observers/eventos que podrían fallar a media
 *     operación dejando la empresa en estado inconsistente.
 *
 * Todo va dentro de una sola transacción — si algo falla, nada se borra.
 * Tablas opcionales (módulos no instalados todavía) se chequean con
 * Schema::hasTable.
 */
class CompanyDataReset
{
    /**
     * Borra TODO lo transaccional + productos.
     *
     * @return array<string,int> tabla => filas borradas (para log/UI)
     */
    public function resetTransactional(Company $company): array
    {
        return $this->run($company, withProducts: true);
    }

    /**
     * Borra TODO lo transaccional pero conserva products + product_locations
     * (al borrar inventory_movements el stock calculado queda en 0).
     *
     * @return array<string,int>
     */
    public function resetTransactionalKeepingProducts(Company $company): array
    {
        return $this->run($company, withProducts: false);
    }

    /**
     * Lista de tablas TRANSACCIONALES (que siempre se borran), en el orden
     * exacto que respeta las FKs. Lo de abajo siempre referencia a lo de arriba.
     *
     * Las tablas que tienen `company_id` directo se borran por WHERE company_id.
     * Las tablas hijas (sin company_id) se borran por WHERE parent_id IN (subquery).
     *
     * Cada item: ['table' => string, 'company_column' => ?string, 'parent_lookup' => ?array]
     * Si company_column es null y parent_lookup es null, NO se borra esa tabla
     * (placeholder por si en el futuro se quiere skip).
     *
     * @return array<int, array{table:string, company_column?:string, parent_table?:string, parent_fk?:string}>
     */
    protected function transactionalTablesOrder(): array
    {
        return [
            // === PASO 1: detalles/líneas (hijos) — borrar antes que sus headers ===
            ['table' => 'sale_invoice_retentions',    'parent_table' => 'sale_invoices',          'parent_fk' => 'sale_invoice_id'],
            ['table' => 'sale_invoice_lines',         'parent_table' => 'sale_invoices',          'parent_fk' => 'sale_invoice_id'],
            ['table' => 'purchase_invoice_lines',     'parent_table' => 'purchase_invoices',      'parent_fk' => 'purchase_invoice_id'],
            ['table' => 'purchase_return_lines',      'parent_table' => 'purchase_returns',       'parent_fk' => 'purchase_return_id'],
            ['table' => 'credit_debit_note_lines',    'parent_table' => 'credit_debit_notes',     'parent_fk' => 'credit_debit_note_id'],
            ['table' => 'quotation_lines',            'parent_table' => 'quotations',             'parent_fk' => 'quotation_id'],
            ['table' => 'delivery_note_lines',        'parent_table' => 'delivery_notes',         'parent_fk' => 'delivery_note_id'],
            ['table' => 'inventory_adjustment_lines', 'parent_table' => 'inventory_adjustments',  'parent_fk' => 'inventory_adjustment_id'],
            ['table' => 'inventory_transfer_lines',   'parent_table' => 'inventory_transfers',    'parent_fk' => 'inventory_transfer_id'],
            ['table' => 'inventory_opening_lines',    'parent_table' => 'inventory_openings',     'parent_fk' => 'inventory_opening_id'],
            ['table' => 'journal_entry_lines',        'parent_table' => 'journal_entries',        'parent_fk' => 'journal_entry_id'],
            ['table' => 'fixed_asset_depreciations',  'parent_table' => 'fixed_assets',           'parent_fk' => 'fixed_asset_id'],
            ['table' => 'payroll_slip_lines',         'parent_table' => 'payroll_slips',          'parent_fk' => 'payroll_slip_id'],
            ['table' => 'partner_movements',          'parent_table' => 'partners',               'parent_fk' => 'partner_id'],
            ['table' => 'warranty_events',            'parent_table' => 'warranties',             'parent_fk' => 'warranty_id'],
            ['table' => 'restaurant_order_items',     'parent_table' => 'restaurant_orders',      'parent_fk' => 'restaurant_order_id'],
            ['table' => 'restaurant_kitchen_tickets', 'parent_table' => 'restaurant_orders',      'parent_fk' => 'restaurant_order_id'],
            ['table' => 'payroll_novelties',          'parent_table' => 'payroll_periods',        'parent_fk' => 'payroll_period_id'],
            ['table' => 'payroll_slips',              'company_column' => 'company_id'],

            // === PASO 2: headers (parents) — borrar después de los hijos ===
            ['table' => 'product_serials',            'company_column' => 'company_id'],
            ['table' => 'warranties',                 'company_column' => 'company_id'],
            ['table' => 'sale_invoices',              'company_column' => 'company_id'],
            ['table' => 'purchase_invoices',          'company_column' => 'company_id'],
            ['table' => 'purchase_returns',           'company_column' => 'company_id'],
            ['table' => 'credit_debit_notes',         'company_column' => 'company_id'],
            ['table' => 'quotations',                 'company_column' => 'company_id'],
            ['table' => 'delivery_notes',             'company_column' => 'company_id'],
            ['table' => 'inventory_adjustments',      'company_column' => 'company_id'],
            ['table' => 'inventory_transfers',        'company_column' => 'company_id'],
            ['table' => 'inventory_openings',         'company_column' => 'company_id'],
            ['table' => 'expenses',                   'company_column' => 'company_id'],
            ['table' => 'fixed_assets',               'company_column' => 'company_id'],
            ['table' => 'suspended_sales',            'company_column' => 'company_id'],
            ['table' => 'restaurant_orders',          'company_column' => 'company_id'],
            ['table' => 'restaurant_reservations',    'company_column' => 'company_id'],
            ['table' => 'payroll_periods',            'company_column' => 'company_id'],
            ['table' => 'payroll_settlements',        'company_column' => 'company_id'],
            ['table' => 'partners',                   'company_column' => 'company_id'],
            ['table' => 'exogena_manual_entries',     'company_column' => 'company_id'],

            // === PASO 3: movimientos contables transversales ===
            ['table' => 'inventory_movements',        'company_column' => 'company_id'],
            ['table' => 'payments',                   'company_column' => 'company_id'],
            ['table' => 'cash_register_sessions',     'company_column' => 'company_id'],

            // === PASO 4: asientos contables (último porque todo lo referencia) ===
            ['table' => 'journal_entries',            'company_column' => 'company_id'],
        ];
    }

    /**
     * Tablas que SOLO se borran en modo "full". En modo "preserve" se mantienen
     * intactas para que el catálogo de productos sobreviva el reset.
     *
     * @return array<int, array{table:string, company_column?:string, parent_table?:string, parent_fk?:string}>
     */
    protected function productTables(): array
    {
        return [
            // product_locations es pivot (sin company_id directo).
            ['table' => 'product_locations',          'parent_table' => 'products',                'parent_fk' => 'product_id'],
            ['table' => 'products',                   'company_column' => 'company_id'],
        ];
    }

    protected function run(Company $company, bool $withProducts): array
    {
        $counts = [];

        DB::transaction(function () use ($company, $withProducts, &$counts) {
            foreach ($this->transactionalTablesOrder() as $entry) {
                $this->deleteEntry($entry, $company->id, $counts);
            }
            if ($withProducts) {
                foreach ($this->productTables() as $entry) {
                    $this->deleteEntry($entry, $company->id, $counts);
                }
            }
        });

        $totalRows = array_sum($counts);
        Log::warning('[CompanyDataReset] Reset ejecutado', [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'mode' => $withProducts ? 'full' : 'preserve_products',
            'total_rows_deleted' => $totalRows,
            'by_table' => array_filter($counts, fn ($n) => $n > 0),
            'triggered_by_user_id' => auth()->id(),
        ]);

        return $counts;
    }

    /**
     * @param  array{table:string, company_column?:string, parent_table?:string, parent_fk?:string}  $entry
     * @param  array<string,int>  $counts
     */
    protected function deleteEntry(array $entry, int $companyId, array &$counts): void
    {
        $table = $entry['table'];

        // Si la tabla no existe (módulo no instalado todavía), se ignora.
        if (! Schema::hasTable($table)) {
            return;
        }

        if (isset($entry['company_column'])) {
            $rows = DB::table($table)
                ->where($entry['company_column'], $companyId)
                ->delete();
        } else {
            // Tabla hija sin company_id directo — borra por subquery sobre el parent.
            $rows = DB::table($table)
                ->whereIn(
                    $entry['parent_fk'],
                    DB::table($entry['parent_table'])
                        ->where('company_id', $companyId)
                        ->select('id'),
                )
                ->delete();
        }

        $counts[$table] = ($counts[$table] ?? 0) + (int) $rows;
    }

    /**
     * Cuenta cuántas filas se borrarían sin ejecutar nada. Útil para el
     * preview del modal de confirmación: "se van a borrar 1.250 movimientos,
     * 340 facturas, 12 asientos contables..."
     *
     * @return array<string,int>
     */
    public function preview(Company $company, bool $withProducts = true): array
    {
        $counts = [];

        foreach ($this->transactionalTablesOrder() as $entry) {
            $this->countEntry($entry, $company->id, $counts);
        }
        if ($withProducts) {
            foreach ($this->productTables() as $entry) {
                $this->countEntry($entry, $company->id, $counts);
            }
        }

        return array_filter($counts, fn ($n) => $n > 0);
    }

    /**
     * @param  array{table:string, company_column?:string, parent_table?:string, parent_fk?:string}  $entry
     * @param  array<string,int>  $counts
     */
    protected function countEntry(array $entry, int $companyId, array &$counts): void
    {
        $table = $entry['table'];
        if (! Schema::hasTable($table)) {
            return;
        }

        if (isset($entry['company_column'])) {
            $rows = DB::table($table)
                ->where($entry['company_column'], $companyId)
                ->count();
        } else {
            $rows = DB::table($table)
                ->whereIn(
                    $entry['parent_fk'],
                    DB::table($entry['parent_table'])
                        ->where('company_id', $companyId)
                        ->select('id'),
                )
                ->count();
        }

        $counts[$table] = (int) $rows;
    }
}
