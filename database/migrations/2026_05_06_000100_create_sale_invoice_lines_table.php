<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de factura de venta.
 * Diferencias vs purchase_invoice_lines:
 *  - unit_price (no unit_cost)
 *  - cost_at_sale: cuánto costaba el producto al momento de vender, para
 *    calcular margen / utilidad por línea sin depender del WAVG futuro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 250);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('cost_at_sale', 18, 4)->default(0); // para reportes de margen
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_percentage', 7, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->foreignId('account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();

            $table->index(['sale_invoice_id', 'line_number']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_invoice_lines');
    }
};
