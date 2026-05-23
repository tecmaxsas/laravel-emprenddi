<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de un ticket de garantía. Cada cambio de estado, comentario
 * o adjunto crea un evento. Permite reconstruir el historial completo
 * para auditoría y soporte: "qué pasó con esta garantía y cuándo".
 *
 * Se inserta automáticamente desde WarrantyEngine cuando cambia status,
 * y manualmente desde la UI cuando un técnico añade un comentario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('event_type', [
                'created',
                'status_change',
                'comment',
                'assigned',
                'attachment',
            ]);

            // Para status_change: estado anterior y nuevo.
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();

            $table->text('comment')->nullable();
            $table->json('payload')->nullable(); // datos adicionales según event_type

            $table->timestamp('created_at')->useCurrent();

            $table->index(['warranty_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_events');
    }
};
