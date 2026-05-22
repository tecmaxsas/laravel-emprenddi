<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del empleado para la depuración de la retención en la fuente:
 * deducciones opcionales que el trabajador certifica (intereses de
 * vivienda, medicina prepagada, dependientes a cargo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('housing_interest_deduction', 18, 2)->nullable()->after('compensation_fund_name');
            $table->decimal('prepaid_health_deduction', 18, 2)->nullable()->after('housing_interest_deduction');
            $table->boolean('has_dependents')->default(false)->after('prepaid_health_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'housing_interest_deduction',
                'prepaid_health_deduction',
                'has_dependents',
            ]);
        });
    }
};
