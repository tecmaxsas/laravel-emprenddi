<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La estadía y su cuenta.
 *
 * Una estadía es una **cuenta abierta**, no una transacción: el huésped entra,
 * se le van cargando noches, el restaurante, la lavandería y el minibar, y
 * todo se cobra junto al salir. Eso es un folio, y se parece mucho más a la
 * orden de una mesa de restaurante que a una sesión de parqueadero (que entra
 * y sale en el mismo rato).
 *
 *   hotel_stays         La cuenta: habitación, huésped, fechas, totales.
 *   hotel_stay_charges  Cada renglón del folio. Las noches también son
 *                       renglones —una por noche— para que el huésped pueda
 *                       ver qué se le cobró y qué día.
 *   hotel_stay_guests   Quiénes duermen en esa habitación. El titular más los
 *                       acompañantes: la tarjeta de registro los pide todos.
 *
 * Los totales viven en columnas y no se calculan al leer porque el folio se
 * consulta todo el tiempo (el mapa de recepción muestra el saldo de cada
 * habitación ocupada) y sumar renglones en cada pintada sería una consulta
 * por habitación. HotelFolio es el único que los escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            // `restrict`: una estadía sin habitación no se puede auditar.
            $table->foreignId('room_id')->constrained('hotel_rooms')->restrictOnDelete();
            $table->foreignId('room_type_id')->nullable()
                ->constrained('hotel_room_types')->nullOnDelete();

            $table->foreignId('reservation_id')->nullable()
                ->constrained('hotel_reservations')->nullOnDelete();

            // A quién se le factura. Si queda null, el check-out factura a
            // Consumidor Final, igual que el POS.
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();
            $table->foreignId('primary_guest_id')->nullable()
                ->constrained('hotel_guests')->nullOnDelete();

            // Número del folio, para que recepción pueda nombrarlo en voz alta.
            $table->string('prefix', 10)->default('H');
            $table->unsignedInteger('number');

            $table->dateTime('check_in_at');
            $table->date('expected_check_out');
            $table->dateTime('check_out_at')->nullable();

            // Noches cobradas. Se recalcula en el check-out: una salida
            // después de la hora pactada puede sumar una noche.
            $table->unsignedSmallInteger('nights')->default(1);

            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);

            $table->decimal('rate_per_night', 14, 2)->default(0);

            $table->string('status', 20)->default('open');     // open|closed|cancelled

            /**
             * Hospedaje exento de IVA.
             *
             * El servicio de alojamiento a un turista extranjero no residente
             * en Colombia es exento (art. 481 ET). No es un caso raro en un
             * hotel, y facturárselo con 19% es cobrarle de más y además
             * declararlo mal. Queda como bandera de la estadía, con el motivo
             * escrito, porque la exención hay que poder sustentarla.
             */
            $table->boolean('tax_exempt')->default(false);
            $table->string('tax_exempt_reason', 180)->nullable();

            // Totales — los escribe HotelFolio, nadie más.
            $table->decimal('room_total', 14, 2)->default(0);
            $table->decimal('consumption_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);

            $table->foreignId('sale_invoice_id')->nullable()
                ->constrained('sale_invoices')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()
                ->constrained('cash_register_sessions')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'hotel_id', 'status']);
            $table->index(['hotel_id', 'room_id', 'status']);
            $table->index(['company_id', 'check_in_at']);
            // Para la consulta de disponibilidad.
            $table->index(['hotel_id', 'status', 'expected_check_out'], 'hotel_stays_dispo_idx');
        });

        Schema::create('hotel_stay_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stay_id')->constrained('hotel_stays')->cascadeOnDelete();

            $table->string('kind', 20);                        // room|product|service|adjustment
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->nullOnDelete();

            $table->date('charge_date');
            // Para kind=room: de qué noche es este renglón. Deja el folio
            // auditable noche por noche en vez de un total opaco.
            $table->date('night_date')->nullable();

            $table->string('description', 255);
            $table->decimal('quantity', 14, 3)->default(1);

            // Precio AL PÚBLICO, igual que la tarifa del tipo de habitación.
            $table->decimal('unit_price', 14, 2)->default(0);

            $table->foreignId('tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);

            $table->decimal('subtotal', 14, 2)->default(0);
            // Puede ser negativo: un kind=adjustment es justamente una
            // rebaja o una cortesía sobre el folio.
            $table->decimal('total', 14, 2)->default(0);

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'stay_id', 'kind']);
            $table->index(['stay_id', 'charge_date']);
        });

        /**
         * Una noche se cobra una sola vez.
         *
         * El pase nocturno que carga las noches puede correr dos veces —se
         * reintenta, o el recepcionista abre el folio mientras corre— y sin
         * esto el huésped amanecería con la misma noche cobrada dos veces.
         * El motor lo verifica igual; esta regla está en la base porque es la
         * única que no se puede saltar llegando por otro camino.
         *
         * Índice parcial y no un UNIQUE normal por dos razones: solo aplica a
         * los renglones de noche (los consumos no tienen `night_date`), y
         * tiene que ignorar los anulados, porque si no, quitar una noche mal
         * cargada impediría volver a cargarla.
         */
        DB::statement(
            'CREATE UNIQUE INDEX hotel_charges_noche_unica ON hotel_stay_charges (stay_id, night_date) '
            ."WHERE kind = 'room' AND deleted_at IS NULL"
        );

        Schema::create('hotel_stay_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stay_id')->constrained('hotel_stays')->cascadeOnDelete();
            // `restrict`: borrar un huésped que estuvo alojado dejaría la
            // tarjeta de registro incompleta.
            $table->foreignId('guest_id')->constrained('hotel_guests')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['stay_id', 'guest_id']);
            $table->index(['company_id', 'guest_id']);
        });

        // Cierra el círculo reserva ↔ estadía. Va aquí porque hotel_stays
        // ya existe: en la migración de reservas la tabla todavía no estaba.
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->foreignId('stay_id')->nullable()->after('guest_id')
                ->constrained('hotel_stays')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stay_id');
        });
        Schema::dropIfExists('hotel_stay_guests');
        Schema::dropIfExists('hotel_stay_charges');
        Schema::dropIfExists('hotel_stays');
    }
};
