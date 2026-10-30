<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifas por temporada.
 *
 * La tarifa vive en el tipo de habitación y eso resuelve el día normal. Pero
 * en Colombia diciembre, Semana Santa y los puentes valen el doble, y hasta
 * ahora la única forma era entrar a cambiar el precio del tipo el 15 de
 * diciembre y acordarse de devolverlo el 15 de enero.
 *
 * Nadie se acuerda. El resultado es vender una noche de diciembre al precio de
 * noviembre —plata que no se recupera— o, peor, seguir cobrando tarifa alta en
 * febrero y que el huésped lo note.
 *
 * Una temporada es un rango de fechas con su precio. Si una noche cae dentro,
 * manda sobre la tarifa del tipo.
 *
 *   room_type_id nulo  →  aplica a todos los tipos del hotel
 *   priority           →  cuando dos temporadas se pisan, gana la mayor
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_rate_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            // Null = todos los tipos. Es el caso normal: «en diciembre todo
            // sube», y no una temporada por cada tipo de habitación.
            $table->foreignId('room_type_id')->nullable()
                ->constrained('hotel_room_types')->cascadeOnDelete();

            $table->string('name', 120);                 // "Temporada alta diciembre"

            /**
             * Las dos fechas son INCLUSIVAS.
             *
             * Al revés que el rango de una estadía, y a propósito: una
             * temporada no son noches, son días del calendario. «Del 15 de
             * diciembre al 15 de enero» incluye los dos, que es como lo dice
             * y lo entiende cualquiera. Exigir que el hotelero ponga el 16 de
             * enero para que valga el 15 es pedirle que piense como la base
             * de datos.
             */
            $table->date('start_date');
            $table->date('end_date');

            // Precio al público por noche, igual que la tarifa del tipo.
            $table->decimal('rate', 14, 2);

            // Null = se usa el del tipo de habitación.
            $table->decimal('extra_person_rate', 14, 2)->nullable();

            // Cuando dos temporadas cubren la misma noche, gana la mayor.
            // Sirve para meter un puente dentro de una temporada más larga.
            $table->unsignedSmallInteger('priority')->default(0);

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['hotel_id', 'active', 'start_date', 'end_date'], 'hotel_seasons_lookup_idx');
            $table->index(['company_id', 'hotel_id']);
        });

        /**
         * Tarifa fija pactada.
         *
         * Con temporadas, cada noche se cobra a lo que valga esa noche. Pero a
         * veces se pacta un precio cerrado —un convenio corporativo, una
         * negociación de grupo— y entonces no se le puede aplicar la
         * temporada por encima.
         *
         * Es una bandera explícita y no algo que el sistema adivine comparando
         * cifras: adivinar significa que un día cobra distinto de lo que el
         * recepcionista cree haber pactado, y eso se descubre con el huésped
         * en el mostrador.
         */
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->boolean('rate_is_fixed')->default(false)->after('rate_per_night');
        });

        Schema::table('hotel_stays', function (Blueprint $table) {
            $table->boolean('rate_is_fixed')->default(false)->after('rate_per_night');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_stays', function (Blueprint $table) {
            $table->dropColumn('rate_is_fixed');
        });

        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropColumn('rate_is_fixed');
        });

        Schema::dropIfExists('hotel_rate_seasons');
    }
};
