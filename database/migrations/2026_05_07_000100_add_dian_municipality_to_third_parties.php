<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dian_municipality_id en third_parties: ID DIAN del municipio del tercero.
 * Necesario al armar el customer del payload UBL hacia apidian.
 *
 * El campo `city` (string) preexiste para uso interno; la FK a dian_municipalities
 * resuelve la ambigüedad para el envío DIAN. Si el tercero no tiene este campo
 * configurado, el builder cae al municipio configurado en la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->foreignId('dian_municipality_id')->nullable()
                ->after('city')
                ->constrained('dian_municipalities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dian_municipality_id');
        });
    }
};
