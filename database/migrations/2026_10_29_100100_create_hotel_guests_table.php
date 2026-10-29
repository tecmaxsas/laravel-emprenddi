<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Huéspedes — el registro de quién se alojó.
 *
 * ¿Por qué no son Terceros y punto?
 *
 * Porque no responden la misma pregunta. El Tercero es **a quién se le
 * factura**: puede ser la empresa que paga la habitación de su empleado, o
 * una agencia. El huésped es **quién durmió ahí**, y de eso la ley pide datos
 * que a la contabilidad no le sirven de nada: nacionalidad, pasaporte,
 * procedencia, destino y motivo del viaje. Es la tarjeta de registro
 * hotelero, obligatoria para todo prestador inscrito en el RNT, y para
 * extranjeros es además lo que se reporta a Migración Colombia.
 *
 * Mezclarlos habría llenado el maestro de Terceros —el mismo que alimenta la
 * exógena y la cartera— de una fila por cada persona que pasó una noche,
 * incluidos los niños sin documento.
 *
 * `third_party_id` existe para cuando el huésped **sí** es el que paga: se
 * enlaza y se factura a su nombre sin volver a digitar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();

            // Nullable porque un menor de edad se registra con el acompañante
            // y muchas veces no lleva documento propio.
            $table->string('document_type', 10)->nullable();   // cc, ce, pa, ti...
            $table->string('document_number', 30)->nullable();

            // Apellidos aparte del nombre: así los pide la tarjeta de registro
            // y así hay que reportarlos. Con un solo campo «nombre completo»
            // habría que adivinar dónde corta.
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();

            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality', 60)->nullable();

            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();

            // Procedencia / destino / motivo: los tres campos del registro
            // hotelero que nadie más del sistema pide.
            $table->string('origin', 120)->nullable();
            $table->string('destination', 120)->nullable();
            $table->string('travel_purpose', 30)->nullable();  // business|leisure|health|study|other

            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Un documento por empresa. El tipo entra en la llave porque la
            // cédula 123 y el pasaporte 123 son dos personas distintas.
            // Postgres admite varios NULL aquí, que es justo lo que hace falta
            // para los huéspedes sin documento.
            $table->unique(['company_id', 'document_type', 'document_number'], 'hotel_guests_documento_unico');
            $table->index(['company_id', 'last_name']);
            $table->index(['company_id', 'document_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_guests');
    }
};
