<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación de resoluciones DIAN a sedes (locations).
 *
 * Cada sede puede tener UNA resolución por cada tipo de documento.
 * El consecutivo actual es CONFIGURABLE — por default arranca en
 * resolution.range_from, pero el usuario puede ponerlo en cualquier
 * número dentro del rango (caso: la resolución ya fue usada antes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_location_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dian_resolution_id')->constrained()->cascadeOnDelete();

            // Consecutivo siguiente a usar. Editable; default = resolution.range_from al asignar.
            $table->unsignedBigInteger('current_consecutive');

            $table->boolean('active')->default(true);
            $table->timestamps();

            // Una location solo puede tener UNA asignación activa por resolución
            $table->unique(['location_id', 'dian_resolution_id']);
            $table->index(['location_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_location_resolutions');
    }
};
