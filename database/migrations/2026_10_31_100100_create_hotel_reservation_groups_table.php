<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas de grupo: el matrimonio, el congreso, la empresa.
 *
 * Hasta ahora una reserva era una habitación. Un grupo de diez eran diez
 * reservas sueltas, diez folios y ninguna forma de cobrarlas juntas — y
 * agencias y corporativos son un pedazo grande del ingreso de un hotel.
 *
 * **El grupo no reemplaza a la reserva: la envuelve.** Cada habitación sigue
 * teniendo su propia reserva, su propio check-in y su propio folio, porque así
 * funciona de verdad: los huéspedes llegan en horas distintas, uno se va
 * antes, otro pide cambiar de habitación. Lo que el grupo aporta son las tres
 * cosas que faltaban:
 *
 *   1. Crear las diez de una vez, no una por una.
 *   2. Verlas juntas y saber cuántas llegaron.
 *   3. **Facturarlas en un solo documento**, que es lo que la empresa o la
 *      agencia necesita para pagar.
 *
 * Esa decisión es la que mantiene intacto el control de sobrecupo: cada
 * reserva del grupo pasa por HotelAvailability igual que cualquier otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_reservation_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            // Derivado del id, igual que el de las reservas: único por
            // construcción y sin contador compartido.
            $table->string('code', 20)->nullable();

            $table->string('name', 150);                    // "Boda Gómez — Pérez"

            // Quién paga. En un grupo casi siempre es una empresa o una
            // agencia, y es contra quien se emite la factura única.
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();

            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 150)->nullable();

            // El rango del bloque. Las reservas pueden salirse de él —alguien
            // llega un día antes— pero sirve para encontrarlo y para el mapa.
            $table->date('check_in_date');
            $table->date('check_out_date');

            $table->string('source', 30)->default('agency');
            $table->string('status', 20)->default('open');  // open|closed|cancelled

            /**
             * Si se factura todo junto.
             *
             * No siempre: hay grupos donde la empresa paga el alojamiento y
             * cada quien sus consumos. Dejarlo elegir evita el caso peor, que
             * es facturarle a la empresa el minibar de alguien.
             */
            $table->boolean('bill_together')->default(true);

            $table->foreignId('sale_invoice_id')->nullable()
                ->constrained('sale_invoices')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'hotel_id', 'status']);
            $table->index(['hotel_id', 'check_in_date']);
        });

        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('hotel_id')
                ->constrained('hotel_reservation_groups')->nullOnDelete();

            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'status']);
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::dropIfExists('hotel_reservation_groups');
    }
};
