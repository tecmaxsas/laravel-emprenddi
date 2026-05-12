<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);  // capturado en post

            // Punteros a los dos movimientos creados al postear, para trazar.
            $table->foreignId('out_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('in_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('inventory_transfer_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lines');
    }
};
