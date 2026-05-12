<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transferencia de inventario entre sedes. Al postearse crea dos
 * InventoryMovement por cada línea:
 *   - transfer_out en from_location_id (cantidad negativa)
 *   - transfer_in  en to_location_id   (cantidad positiva)
 * Cost = currentAvgCost del producto en la sede origen al momento de
 * la transferencia (no se gana ni pierde valor por mover stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();

            $table->string('prefix', 10)->default('TR');
            $table->unsignedBigInteger('number');

            $table->date('date');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'status', 'date']);
            $table->index(['company_id', 'from_location_id']);
            $table->index(['company_id', 'to_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
