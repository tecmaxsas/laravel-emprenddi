<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_taking_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained('order_taking_orders')
                ->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();

            $table->smallInteger('line_number');
            $table->string('description', 250)->nullable();

            $table->decimal('quantity_ordered', 12, 3);
            // quantity_delivered lo mantenemos como columna en vez de solo
            // computed: acelera listados/filtros y evita queries N+1 en el
            // listado de pedidos. Se actualiza desde el engine al registrar
            // o borrar un delivery.
            $table->decimal('quantity_delivered', 12, 3)->default(0);

            $table->decimal('unit_price_before_tax', 14, 4);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('unit_price_at_public', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('total', 14, 2);

            $table->timestamps();

            $table->unique(['order_id', 'line_number']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_taking_order_items');
    }
};
