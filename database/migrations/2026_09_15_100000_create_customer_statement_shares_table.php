<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces públicos de estados de cuenta, para mandarlos por WhatsApp.
 *
 * No guarda el estado de cuenta: guarda el permiso para verlo. La hoja se arma
 * en vivo cada vez que el cliente abre el enlace, así que si abona hoy y mira el
 * enlace mañana ve el saldo nuevo, no una foto vieja.
 *
 * Dos cosas lo hacen razonablemente seguro para algo que viaja por WhatsApp:
 *
 *   - El `token` es aleatorio de 40 caracteres. No se puede adivinar ni contar
 *     desde otro: nadie llega al estado de cuenta del vecino cambiando un número.
 *   - Caduca. Un estado de cuenta es información financiera de un tercero y no
 *     tiene por qué quedar accesible para siempre en un chat reenviado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_statement_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained()->cascadeOnDelete();

            $table->string('token', 40)->unique();

            // El mismo rango que tenía la pantalla al compartir, para que el
            // cliente vea exactamente lo que le mostraron.
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            // A qué número se mandó. Sirve para saber a quién se le compartió
            // sin tener que recordar la conversación de WhatsApp.
            $table->string('sent_to', 30)->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'third_party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_statement_shares');
    }
};
