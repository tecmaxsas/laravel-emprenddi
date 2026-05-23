<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plazo de garantía por producto, en días desde la venta. 0 = sin garantía.
 * Usado por el módulo de garantías para calcular fecha de vencimiento al
 * crear un ticket. Default 0 para no asumir cobertura en productos que
 * históricamente no la tenían.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_days')->default(0)->after('tracks_serials');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('warranty_days');
        });
    }
};
