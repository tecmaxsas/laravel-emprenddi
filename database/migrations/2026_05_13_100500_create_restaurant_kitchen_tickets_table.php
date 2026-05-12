<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro histórico de comandas (KOTs - Kitchen Order Tickets) impresas
 * por orden + impresora. Cada vez que se manda items a cocina/barra se
 * crea un ticket por destino con un batch number incremental
 * (1=primera ronda, 2=segunda, etc.).
 *
 * Sirve para auditoría y reimpresión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_printer_id')
                ->constrained('restaurant_printers')->restrictOnDelete();

            $table->unsignedSmallInteger('batch_number');  // 1, 2, 3...
            $table->jsonb('items_snapshot');  // [{description, qty, modifiers, note}]

            $table->enum('status', ['printed', 'failed', 'cancelled'])->default('printed');
            $table->text('error_message')->nullable();

            $table->foreignId('printed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->useCurrent();

            $table->timestamps();

            $table->index(['restaurant_order_id', 'batch_number']);
            $table->index(['restaurant_printer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_kitchen_tickets');
    }
};
