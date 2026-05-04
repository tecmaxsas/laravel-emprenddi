<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de remisión.
 * unit_price/subtotal son INFORMATIVOS (la factura posterior maneja precio
 * real). No tiene impuestos — la facturación los aplica.
 *
 * inventory_movement_id se setea al despachar y se hereda a sale_invoice_lines
 * cuando la remisión se factura, para que el SaleInvoiceEngine sepa que ya
 * hubo movimiento de inventario y NO genere uno nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 250);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('cost_at_dispatch', 18, 4)->default(0);
            $table->foreignId('inventory_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();

            $table->index(['delivery_note_id', 'line_number']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
    }
};
