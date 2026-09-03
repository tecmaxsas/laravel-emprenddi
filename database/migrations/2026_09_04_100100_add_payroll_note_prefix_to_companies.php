<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prefijo de las notas de ajuste de nomina.
 *
 * Va aparte del de la nomina: son dos rangos distintos ante la DIAN (tipos 9 y
 * 10) y se registran por separado. Hasta ahora el de la nota se escribia en el
 * formulario, se mandaba a apidian y se perdia, asi que al enviar una nota no
 * habia de donde sacarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('payroll_note_prefix', 10)->nullable()->after('payroll_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payroll_note_prefix');
        });
    }
};
