<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los mensajes de una conversación.
 *
 * Se guarda también qué consultas hizo Claude a la base (`tools`) y cuántos
 * tokens costó cada respuesta. Lo primero para que el usuario pueda ver de
 * dónde salió una cifra; lo segundo porque el saldo se descuenta por token y
 * un cobro sin respaldo no se puede explicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20);              // user | assistant
            $table->text('content')->nullable();

            // Qué herramientas usó y con qué argumentos.
            $table->jsonb('tools')->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_cop', 14, 2)->default(0);

            // Si la llamada falló, el mensaje queda igual con el motivo: una
            // conversación con un hueco es peor que una con un error visible.
            $table->text('error')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['ai_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
