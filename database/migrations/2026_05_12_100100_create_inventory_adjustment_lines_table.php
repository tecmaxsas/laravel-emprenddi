<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_adjustment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);

            // Solo lo llenamos cuando direction='in'. Para 'out' el engine
            // toma el costo promedio actual del producto en la sede.
            $table->decimal('unit_cost', 15, 4)->default(0);

            // Puntero al movimiento de inventario que se creó al postear.
            $table->foreignId('inventory_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('inventory_adjustment_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_lines');
    }
};
