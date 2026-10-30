<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de alimentación: solo alojamiento, con desayuno, media pensión…
 *
 * Muy común en Colombia y hasta ahora no existía: una tarifa por tipo de
 * habitación y punto. El hotel que vende «doble con desayuno» tenía que crear
 * un tipo de habitación aparte —duplicando la Doble entera— o meter el
 * desayuno en la tarifa y perder la trazabilidad de qué parte es alojamiento
 * y qué parte es comida.
 *
 * Dos formas de venderlo, y las dos se usan:
 *
 *   `included_in_rate = true`   La tarifa ya lo incluye. No se cobra nada
 *                               aparte: el plan solo queda escrito en el folio
 *                               y en la tarjeta de registro, que es lo que el
 *                               huésped necesita saber para bajar a desayunar.
 *
 *   `included_in_rate = false`  Se cobra por persona y por noche, como un
 *                               renglón propio del folio. Así el hotel sabe
 *                               cuánto vendió de alojamiento y cuánto de
 *                               alimentación, que son dos negocios distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            $table->string('code', 20);                 // EP, CP, MAP, AP, AI
            $table->string('name', 100);                // "Alojamiento + desayuno"
            $table->text('description')->nullable();

            // Si la tarifa ya lo incluye, no se cobra nada: solo se informa.
            $table->boolean('included_in_rate')->default(false);

            // Precio al público por persona y por noche, cuando se cobra aparte.
            $table->decimal('price_per_adult', 14, 2)->default(0);
            $table->decimal('price_per_child', 14, 2)->default(0);

            /**
             * El producto con el que se factura.
             *
             * Opcional pero recomendado: colgándolo de un producto, el cargo
             * hereda su IVA y su cuenta de ingreso, y la alimentación queda
             * separada del alojamiento en el estado de resultados. Sin él, el
             * renglón se cobra sin impuesto.
             */
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['hotel_id', 'code']);
            $table->index(['company_id', 'hotel_id', 'active']);
        });

        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->foreignId('meal_plan_id')->nullable()->after('room_type_id')
                ->constrained('hotel_meal_plans')->nullOnDelete();
        });

        Schema::table('hotel_stays', function (Blueprint $table) {
            $table->foreignId('meal_plan_id')->nullable()->after('room_type_id')
                ->constrained('hotel_meal_plans')->nullOnDelete();
        });

        /**
         * El plan se cobra una vez por noche, igual que la habitación.
         *
         * Mismo índice parcial que las noches de alojamiento y por la misma
         * razón: el pase nocturno puede correr dos veces y sin esto el huésped
         * amanecería con dos desayunos cobrados. Va aparte del de `room`
         * porque son dos renglones distintos de la misma noche.
         */
        DB::statement(
            'CREATE UNIQUE INDEX hotel_charges_plan_unico ON hotel_stay_charges (stay_id, night_date) '
            ."WHERE kind = 'meal' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS hotel_charges_plan_unico');

        Schema::table('hotel_stays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meal_plan_id');
        });

        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meal_plan_id');
        });

        Schema::dropIfExists('hotel_meal_plans');
    }
};
