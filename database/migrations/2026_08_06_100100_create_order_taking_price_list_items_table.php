<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_taking_price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')
                ->constrained('order_taking_price_lists')
                ->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Precios del ítem — todos en la moneda de la compañía (COP).
            // price_before_tax + tax_amount = price_at_public. Guardamos los 3
            // para no depender de recalculo (evita drift de redondeo cuando el
            // XLSX original ya trae el importe redondeado y no la tasa exacta).
            $table->decimal('price_before_tax', 14, 4)->default(0);
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('price_at_public', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['price_list_id', 'product_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_taking_price_list_items');
    }
};
