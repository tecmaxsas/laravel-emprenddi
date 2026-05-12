<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesas del restaurante. Cada mesa pertenece a una zona y tiene
 * coordenadas X/Y en el mapa visual de la zona (sistema de píxeles 0-1000
 * para que escale en cualquier resolución).
 *
 * shape: visual de la mesa en el mapa (cuadrada / redonda / barra).
 * status: free, occupied, reserved, billing (cuenta pedida).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()
                ->constrained('restaurant_service_zones')->nullOnDelete();

            $table->string('code', 20);  // "M1", "T-5", "VIP-3"
            $table->string('label', 60)->nullable();  // "Mesa terraza grande"

            $table->unsignedSmallInteger('capacity')->default(4);

            $table->enum('shape', ['square', 'round', 'rect', 'bar'])->default('square');
            $table->unsignedSmallInteger('pos_x')->default(50);  // 0..1000 en el lienzo
            $table->unsignedSmallInteger('pos_y')->default(50);
            $table->unsignedSmallInteger('width')->default(80);   // tamaño visual
            $table->unsignedSmallInteger('height')->default(80);

            $table->enum('status', ['free', 'occupied', 'reserved', 'billing', 'cleaning'])->default('free');
            $table->boolean('active')->default(true);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'location_id', 'code']);
            $table->index(['company_id', 'location_id', 'zone_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
