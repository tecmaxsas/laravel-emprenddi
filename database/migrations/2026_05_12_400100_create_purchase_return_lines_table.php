<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('description', 250);

            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);

            $table->foreignId('tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);

            $table->decimal('total', 18, 2)->default(0);

            // Trazabilidad opcional al renglón original de la compra.
            $table->foreignId('source_purchase_line_id')->nullable()
                ->constrained('purchase_invoice_lines')->nullOnDelete();

            // Movimiento de inventario generado al postear.
            $table->foreignId('inventory_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();

            $table->timestamps();

            $table->index('purchase_return_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
    }
};
