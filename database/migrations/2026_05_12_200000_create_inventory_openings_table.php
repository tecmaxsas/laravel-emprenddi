<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apertura / saldo inicial de inventario por sede. Se usa al iniciar el
 * sistema o al dar de alta una nueva sede para cargar las cantidades
 * existentes sin pasar por una factura de compra.
 *
 *   DR  cuenta de inventario del producto (1435 / configurada)
 *   CR  counterpart_account_id  (típicamente 3705 Resultados ejercicios
 *       anteriores, o 3115 Capital social, según el caso)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->string('prefix', 10)->default('SI');
            $table->unsignedBigInteger('number');

            $table->date('date');

            $table->foreignId('counterpart_account_id')
                ->constrained('accounts')->restrictOnDelete();

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
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
            $table->index(['company_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_openings');
    }
};
