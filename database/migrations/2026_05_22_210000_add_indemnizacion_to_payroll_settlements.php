<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indemnización por despido sin justa causa — componente de la
 * liquidación definitiva. A diferencia de las prestaciones, no se
 * provisiona: es un gasto del momento de la terminación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settlements', function (Blueprint $table) {
            $table->decimal('amount_indemnizacion', 18, 2)->default(0)->after('amount_vacaciones');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settlements', function (Blueprint $table) {
            $table->dropColumn('amount_indemnizacion');
        });
    }
};
