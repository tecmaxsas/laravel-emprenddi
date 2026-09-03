<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutivo del set de pruebas de nomina.
 *
 * La DIAN pide 10 nominas y 10 notas de ajuste para habilitar, cada una con
 * su consecutivo. Guardarlo evita que el usuario lleve la cuenta a mano entre
 * recargas de la pagina y que repita un numero, que la DIAN rechaza.
 *
 * Va aparte del consecutivo real de la empresa: las pruebas usan el prefijo
 * SETP y no deben quemar numeracion de produccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->unsignedInteger('payroll_test_consecutive')->default(1)->after('payroll_test_set_id');
        });
    }

    public function down(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->dropColumn('payroll_test_consecutive');
        });
    }
};
