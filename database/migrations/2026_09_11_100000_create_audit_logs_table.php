<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría: quién hizo qué, cuándo y desde dónde.
 *
 * Hasta ahora la única huella eran las columnas `created_by_user_id` /
 * `posted_by_user_id` regadas por veintitantas tablas. Sirven para saber quién
 * creó un documento, pero no quién lo modificó después, ni qué le cambió, ni
 * quién lo borró, ni quién entró a la plataforma. Un administrador que quiera
 * responder «¿quién cambió el precio de este producto el martes?» no tenía
 * dónde mirar.
 *
 * Decisiones de forma:
 *
 *   - Solo `created_at`. Un registro de auditoría que se puede actualizar no es
 *     auditoría. El modelo lo refuerza en código.
 *   - `user_name` y `user_email` son copias del momento. Si el empleado se va y
 *     se borra el usuario, la bitácora tiene que seguir diciendo quién fue.
 *   - `changes` guarda solo lo que cambió, con valor anterior y nuevo. Guardar
 *     la fila completa multiplicaría el tamaño sin agregar respuestas.
 *   - `auditable_type`/`auditable_id` sin llave foránea: el registro sobrevive
 *     al borrado de aquello que describe, que es justamente cuando más importa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // El usuario puede desaparecer; el rastro no.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 150)->nullable();
            $table->string('user_email', 150)->nullable();

            // created | updated | deleted | restored | login | logout | login_failed
            $table->string('event', 30);

            // Sobre qué. Sin FK a propósito.
            $table->string('auditable_type', 150)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Cómo se llama eso para un humano: «FV-1001», «Laura Mejía».
            $table->string('auditable_label', 200)->nullable();

            // Lo que cambió: {"campo": {"antes": …, "despues": …}}
            $table->json('changes')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('url', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // La consulta normal es «la empresa X, lo más reciente primero».
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'user_id', 'created_at']);
            $table->index(['company_id', 'event', 'created_at']);
            // «Todo lo que le pasó a esta factura».
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
