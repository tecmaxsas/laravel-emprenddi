<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drivers (repartidores) del restaurante. Modelo dedicado — no usuarios
 * del sistema. Asignados a Orders con is_delivery=true via delivery_metadata
 * .driver_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('phone', 30)->nullable();
            $table->string('license_plate', 20)->nullable();  // placa de moto
            $table->string('vehicle_type', 30)->nullable();    // moto, bicicleta, etc.
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_drivers');
    }
};
