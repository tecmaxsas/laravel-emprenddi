<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Períodos fiscales mensuales/anuales. Un período cerrado bloquea
 * cualquier asiento, movimiento de inventario o pago con fecha dentro
 * del rango. El registro se crea on-demand cuando el contador decide
 * cerrar un mes — si no existe, el período se considera abierto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');  // 1..12, o 0 para "cierre anual completo"

            $table->date('starts_on');
            $table->date('ends_on');

            $table->enum('status', ['open', 'closed'])->default('closed');

            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'year', 'month']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
