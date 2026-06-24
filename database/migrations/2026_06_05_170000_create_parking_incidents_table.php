<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de incidentes operativos del parqueadero: daños al vehiculo,
 * robos, accidentes, conductas irregulares, mantenimiento. Cada incidente
 * puede o no estar atado a una sesion (a veces se descubren despues).
 *
 * Severidad y estado para ordenar/filtrar. photo_paths JSON guarda multiples
 * fotos (evidencia). Cierra el bucle con resolved_at + resolved_notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parking_lot_id')->constrained('parking_lots')->cascadeOnDelete();
            $table->foreignId('parking_session_id')->nullable()
                ->constrained('parking_sessions')->nullOnDelete();

            // Tipos: damage (daño), theft (robo), accident, irregular,
            //        maintenance, other
            $table->string('kind', 30);

            // low | medium | high | critical
            $table->string('severity', 20)->default('medium');

            // open | investigating | resolved | dismissed
            $table->string('status', 20)->default('open');

            $table->string('plate', 20)->nullable();   // si no hay session
            $table->text('description');
            $table->json('photo_paths')->nullable();   // array de paths storage

            $table->foreignId('reported_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolved_notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'parking_lot_id', 'status']);
            $table->index(['company_id', 'kind', 'severity']);
            $table->index(['plate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_incidents');
    }
};
