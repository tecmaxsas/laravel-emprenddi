<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_opening_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_opening_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);

            $table->foreignId('inventory_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('inventory_opening_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_opening_lines');
    }
};
