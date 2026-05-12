<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de inventario — entrada (sobrante / recuperación / encontrado)
 * o salida (pérdida / deterioro / faltante / vencido) en una sola sede.
 *
 *  direction = 'in':
 *    DR  cuenta de inventario (producto)
 *    CR  counterpart_account_id  (4255 Recuperaciones, p.ej.)
 *
 *  direction = 'out':
 *    DR  counterpart_account_id  (5295 Gastos diversos, p.ej.)
 *    CR  cuenta de inventario (producto)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->string('prefix', 10)->default('AJ');
            $table->unsignedBigInteger('number');

            $table->date('date');
            $table->enum('direction', ['in', 'out']);
            $table->enum('reason_code', ['damage', 'loss', 'count', 'expiration', 'found', 'other'])->default('other');
            $table->text('reason_description')->nullable();

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
        Schema::dropIfExists('inventory_adjustments');
    }
};
