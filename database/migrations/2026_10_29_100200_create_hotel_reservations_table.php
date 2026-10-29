<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas.
 *
 * Una reserva puede nacer **sin habitación asignada**: el cliente llama y
 * aparta «una doble para el viernes», y la habitación concreta se decide
 * después, a veces el mismo día de la llegada. Por eso `room_id` es nullable
 * y `room_type_id` es lo que de verdad se aparta.
 *
 * Fechas como DATE, no datetime: lo que se aparta son noches, y la hora de
 * llegada real la fija el hotel (hotels.check_in_time). Guardar un datetime
 * invitaría a comparar horas y a que dos reservas del mismo día parezcan no
 * solaparse.
 *
 * Convenio de rango, que es la decisión que evita el sobrecupo:
 * `check_in_date` es inclusiva y `check_out_date` EXCLUSIVA. Quien sale el
 * día 10 deja la habitación libre para quien llega el 10. Dos rangos se
 * solapan si `check_in_date < otro_check_out AND check_out_date > otro_check_in`.
 * Esa regla vive escrita una sola vez, en HotelAvailability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            // Se ancla al asignar. Hasta entonces la reserva es de un tipo.
            $table->foreignId('room_id')->nullable()
                ->constrained('hotel_rooms')->nullOnDelete();
            $table->foreignId('room_type_id')->nullable()
                ->constrained('hotel_room_types')->nullOnDelete();

            // Quién paga (si ya se sabe) y quién se aloja (si ya está registrado).
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();
            $table->foreignId('guest_id')->nullable()
                ->constrained('hotel_guests')->nullOnDelete();

            /**
             * Código de confirmación que se le dicta al cliente por teléfono.
             *
             * Nullable solo por un instante: se deriva del id y se escribe en
             * la misma transacción que crea la reserva. Derivarlo del id en vez
             * de llevar un consecutivo aparte evita el candado y la cola que
             * trae todo contador compartido, y no hay forma de que se repita.
             */
            $table->string('code', 20)->nullable();

            // Datos de contacto sueltos: una reserva telefónica se toma en
            // treinta segundos y no justifica crear un huésped todavía.
            $table->string('guest_name', 120);
            $table->string('guest_phone', 30)->nullable();
            $table->string('guest_email', 150)->nullable();

            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedSmallInteger('nights')->default(1);

            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);

            // Snapshot de la tarifa pactada. Si el tipo sube de precio
            // mañana, lo prometido al cliente no cambia.
            $table->decimal('rate_per_night', 14, 2)->default(0);
            $table->decimal('estimated_total', 14, 2)->default(0);
            $table->decimal('deposit_amount', 14, 2)->default(0);

            $table->string('source', 30)->default('direct');   // direct|phone|whatsapp|walk_in|booking|airbnb|agency|other
            $table->string('status', 20)->default('pending');   // pending|confirmed|checked_in|no_show|cancelled|completed

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'hotel_id', 'status']);
            // El índice que usa la consulta de disponibilidad.
            $table->index(['hotel_id', 'check_in_date', 'check_out_date'], 'hotel_res_rango_idx');
            $table->index(['hotel_id', 'room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_reservations');
    }
};
