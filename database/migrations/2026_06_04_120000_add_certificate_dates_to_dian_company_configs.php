<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega fechas de expedicion y vencimiento del certificado .p12 a la
 * configuracion DIAN. Estos datos NO se envian a apidian — son locales
 * y se usan para alertar al usuario sobre el vencimiento (y, en el
 * futuro, enviar notificaciones previas a la expiracion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->date('certificate_expedition_date')->nullable()
                ->after('certificate_filename');
            $table->date('certificate_expiration_date')->nullable()
                ->after('certificate_expedition_date');
        });
    }

    public function down(): void
    {
        Schema::table('dian_company_configs', function (Blueprint $table) {
            $table->dropColumn(['certificate_expedition_date', 'certificate_expiration_date']);
        });
    }
};
