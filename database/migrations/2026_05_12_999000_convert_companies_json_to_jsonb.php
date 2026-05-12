<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres no soporta DISTINCT / GROUP BY / ORDER BY sobre columnas
 * tipo json (no tienen operador de igualdad). Eso explotó al renderizar
 * el AttachAction de Filament en el portal contador con:
 *   SQLSTATE[42883]: could not identify an equality operator for type json
 *
 * Convertimos TODAS las columnas json a jsonb (tipo nativo Postgres que
 * sí permite igualdad e indexación). La API de Laravel sigue siendo
 * idéntica — el cast 'array' en el modelo lee y escribe transparente.
 *
 * No-op en MySQL/SQLite (no tienen tipo jsonb separado).
 */
return new class extends Migration
{
    /** [table => [columns]] */
    protected array $columns = [
        'companies' => ['active_modules', 'settings'],
        'plans' => ['features', 'limits'],
        'third_parties' => ['tax_responsibilities'],
        'locations' => ['settings'],
        'products' => ['attributes'],
        'invoice_templates' => ['settings'],
        'suspended_sales' => ['cart_snapshot', 'payments_snapshot'],
        'sale_invoices' => ['dian_response'],
        'cash_register_sessions' => ['payment_breakdown'],
        'credit_debit_notes' => ['dian_response'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) continue;
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$col}\" TYPE jsonb USING \"{$col}\"::jsonb");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) continue;
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$col}\" TYPE json USING \"{$col}\"::json");
            }
        }
    }
};
