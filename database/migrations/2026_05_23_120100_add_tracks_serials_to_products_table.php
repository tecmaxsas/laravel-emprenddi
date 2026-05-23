<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag por producto que activa el manejo de seriales. Cuando es true
 * se exige cantidad == número de seriales en compras/ajustes y se
 * vinculan ProductSerial individuales a cada línea de venta.
 *
 * Default false para no romper productos existentes (cables, servicios,
 * consumibles). La UI del campo solo se muestra si la feature global
 * (Company.settings.serials.enabled) está activada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tracks_serials')->default(false)->after('track_inventory');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tracks_serials');
        });
    }
};
