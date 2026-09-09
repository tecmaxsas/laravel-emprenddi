<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El saldo de Claude, como libro de movimientos y no como un número suelto.
 *
 * Mismo patrón que el kardex de inventario: cada fila trae el saldo que quedó
 * después, así el saldo actual es el del último movimiento y siempre se puede
 * explicar cómo se llegó a él. Un campo `saldo` en la tabla de empresas sería
 * más fácil de leer y imposible de auditar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);               // recarga | consumo | ajuste
            $table->decimal('amount_cop', 14, 2);     // positivo suma, negativo resta
            $table->decimal('balance_after', 14, 2);

            $table->string('description', 250)->nullable();

            $table->foreignId('ai_conversation_id')->nullable()
                ->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_movements');
    }
};
