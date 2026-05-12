<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por (activo, año, mes). Cada fila representa la depreciación
 * mensual contabilizada y apunta al JournalEntry generado. Sirve para
 * idempotencia: no se puede depreciar dos veces el mismo mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');  // 1..12

            $table->decimal('amount', 18, 2);

            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fixed_asset_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
