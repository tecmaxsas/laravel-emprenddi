<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de cotización. Estructura más simple que sale_invoice_lines: no
 * lleva cost_at_sale ni inventory_movement_id ni account_id porque la
 * cotización no toca inventario ni contabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 250);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_percentage', 7, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['quotation_id', 'line_number']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
    }
};
