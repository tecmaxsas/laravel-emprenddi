<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->timestamp('date');
            $table->enum('type', [
                'opening',
                'purchase',
                'sale',
                'adjustment_in',
                'adjustment_out',
                'transfer_in',
                'transfer_out',
                'return_to_supplier',
                'return_from_customer',
            ]);

            // quantity firmada: + entrada, − salida.
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);

            // Saldos acumulados después del movimiento (para Kardex sin aggregations).
            $table->decimal('balance_quantity_after', 15, 4)->default(0);
            $table->decimal('balance_cost_after', 15, 4)->default(0);
            $table->decimal('balance_value_after', 18, 2)->default(0);

            // Para FIFO/LIFO en futuro: cantidad restante de este lote.
            $table->decimal('remaining_quantity', 15, 4)->nullable();

            // Trazabilidad polimórfica (compra, venta, ajuste, etc.).
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 100)->nullable();

            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'location_id', 'date']);
            $table->index(['product_id', 'location_id', 'date']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
