<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Habilitacion de nomina electronica ante la DIAN.
 *
 * Es un tramite APARTE del de facturacion, con su propio software y su propio
 * set de pruebas, aunque la empresa ya este habilitada para facturar: la DIAN
 * pide 10 nominas y 10 notas de ajuste, de las que tienen que aceptarse 4 y 4.
 *
 * Por eso el id y el pin del software van en columnas propias y no se reusan
 * los de facturacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->string('software_payroll_id')->nullable()->after('software_pin');
            $table->string('software_payroll_pin')->nullable()->after('software_payroll_id');
            $table->boolean('payroll_software_configured')->default(false)->after('software_payroll_pin');

            // TestSetId del set de pruebas de nomina: lo entrega el portal de
            // la DIAN y es distinto al de facturacion.
            $table->string('payroll_test_set_id')->nullable()->after('payroll_software_configured');
        });
    }

    public function down(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->dropColumn([
                'software_payroll_id',
                'software_payroll_pin',
                'payroll_software_configured',
                'payroll_test_set_id',
            ]);
        });
    }
};
