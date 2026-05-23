<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices en columnas FK que se usan en WHERE pero no estaban indexadas.
 * PostgreSQL no indexa automáticamente columnas FK del lado hijo
 * (a diferencia de MySQL), así que las queries que filtran por estas
 * columnas hacían sequential scan.
 *
 *   journal_entry_lines.cost_center_id → filtrado en Libro Mayor/Auxiliar
 *   payroll_slips.employment_contract_id → joins en reportes de nómina
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->index('cost_center_id');
        });

        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->index('employment_contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['cost_center_id']);
        });

        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->dropIndex(['employment_contract_id']);
        });
    }
};
