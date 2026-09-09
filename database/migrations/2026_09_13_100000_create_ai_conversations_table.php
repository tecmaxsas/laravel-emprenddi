<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversaciones con Claude.
 *
 * Se conservan para poder retomarlas: el usuario cierra el navegador, vuelve
 * mañana y sigue donde iba. Por eso cuelgan de la empresa y del usuario, no de
 * la sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Se arma con la primera pregunta: nadie titula sus conversaciones.
            $table->string('title', 200);

            $table->string('model', 60)->nullable();

            // Para ordenar por actividad y no por fecha de creación.
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'user_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
