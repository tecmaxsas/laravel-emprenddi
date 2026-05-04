<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de NC/ND. Estructura espeja sale_invoice_lines salvo que aquí
 * inventory_movement_id solo se setea cuando NC con affects_inventory=true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_debit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_debit_note_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 250);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('cost_at_return', 18, 4)->default(0); // para COGS reversal
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

            $table->index(['credit_debit_note_id', 'line_number']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_debit_note_lines');
    }
};
