<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zonas de atención del restaurante: salón principal, terraza, barra,
 * cocina, etc. Cada zona puede tener una impresora default; cada
 * categoría de producto puede enrutar a otra impresora distinta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_service_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->string('code', 20)->nullable();
            $table->string('color', 9)->default('#3b82f6');  // hex para el mapa
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'location_id', 'active']);
            $table->index(['company_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_service_zones');
    }
};
