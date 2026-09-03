<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ambiente de nomina, aparte del de facturacion.
 *
 * La DIAN valida el atributo Ambiente del documento (regla NIE023): 2 en
 * habilitacion, 1 en produccion. Y las dos habilitaciones son tramites
 * independientes, asi que una empresa que ya factura en produccion tiene que
 * poder enviar su set de pruebas de nomina en habilitacion al mismo tiempo.
 *
 * Antes se mandaba el mismo valor a los tres ambientes que expone apidian, lo
 * que dejaba la nomina en produccion y la DIAN rechazaba todo el set.
 *
 * Arranca en 2 (habilitacion): ninguna empresa emite nomina en produccion sin
 * pasar antes por el set de pruebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('payroll_environment')->default(2)->after('payroll_test_consecutive');
        });
    }

    public function down(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->dropColumn('payroll_environment');
        });
    }
};
