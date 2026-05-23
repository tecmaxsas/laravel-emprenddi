<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices en columnas FK que se usan en WHERE pero no estaban indexadas.
 * PostgreSQL no indexa automáticamente columnas FK del lado hijo
 * (a diferencia de MySQL), así que las queries que filtran por estas
 * columnas hacían sequential scan.
 *
 *   journal_entry_lines.cost_center_id    → filtrado en Libro Mayor/Auxiliar
 *   payroll_slips.employment_contract_id  → joins en reportes de nómina
 *
 * Usa CREATE INDEX IF NOT EXISTS para ser idempotente: el índice de
 * cost_center_id ya lo había creado la migración de cost_centers; el
 * de payroll_slips no existía. Esta migración cierra ambos casos sin
 * romper en bases donde alguno ya estuviera presente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS journal_entry_lines_cost_center_id_index ON journal_entry_lines (cost_center_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS payroll_slips_employment_contract_id_index ON payroll_slips (employment_contract_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payroll_slips_employment_contract_id_index');
        // No se borra el de cost_center_id en el down porque originalmente
        // fue creado por la migración de cost_centers.
    }
};
