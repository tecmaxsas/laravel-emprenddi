<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada producto pedido en una orden. Tiene su propio status individual
 * porque en una mesa de 4 personas la pizza puede estar lista mientras
 * la sopa sigue en cocina.
 *
 *  kitchen_status flujo:
 *    pending → sent → preparing → ready → served
 *    (cancelled en cualquier momento)
 *
 *  modifiers: jsonb con { extras: [{name, price}], remove: [name], note }
 *  course: 1=entrada, 2=fuerte, 3=postre — para impresión secuencial
 *  split_tab: etiqueta string para división de cuenta ("Persona 1", "Tab A")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->foreignId('product_id')->nullable()
                ->constrained('products')->restrictOnDelete();
            $table->string('description', 250);

            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);

            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);

            $table->foreignId('tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);

            $table->decimal('total', 18, 2)->default(0);

            // Modificadores + nota
            $table->jsonb('modifiers')->nullable();
            $table->text('item_note')->nullable();  // "sin cebolla", "bien cocido"

            // Cocina
            $table->enum('kitchen_status', ['pending', 'sent', 'preparing', 'ready', 'served', 'cancelled'])
                ->default('pending');
            $table->unsignedTinyInteger('course')->default(1);  // 1..N orden de servicio
            $table->timestamp('sent_to_kitchen_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            // Para qué impresora se imprimió (se llena al mandar comanda)
            $table->foreignId('printed_to_printer_id')->nullable()
                ->constrained('restaurant_printers')->nullOnDelete();
            $table->unsignedSmallInteger('kot_batch')->default(0);  // qué KOT lo trajo

            // División de cuenta
            $table->string('split_tab', 30)->nullable();  // "Persona 1", etc.

            $table->timestamps();

            $table->index('restaurant_order_id');
            $table->index(['restaurant_order_id', 'kitchen_status']);
            $table->index(['printed_to_printer_id', 'kitchen_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_order_items');
    }
};
