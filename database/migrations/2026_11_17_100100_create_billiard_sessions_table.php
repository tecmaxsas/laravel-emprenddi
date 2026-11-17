<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo Billares — la cuenta de la mesa.
 *
 *   billiard_sessions          Una cuenta abierta: quien esta en la mesa,
 *                              desde cuando, cuanto lleva y cuanto debe.
 *   billiard_session_charges   Cada renglon de esa cuenta: el tiempo y
 *                              cada cerveza. Mismo diseño que el folio del
 *                              hotel (hotel_stay_charges), por la misma
 *                              razon: la cuenta se revisa renglon por
 *                              renglon con el cliente al frente.
 *   billiard_session_pauses    Cada pausa, con su hora y su motivo.
 *
 * ¿Por que una tabla de pausas y no un contador?
 *
 * Porque la pausa es lo que mas se discute en un billar. El cliente para
 * el reloj para ir a comer y al volver reclama que le siguieron cobrando;
 * el operario jura que lo paro. Un contador de segundos no responde esa
 * pregunta: hay que poder decir «se paro a las 8:14 y se reanudo a las
 * 8:52, lo hizo Marcela». Tres columnas mas cuestan nada y evitan la
 * discusion que de verdad pasa.
 *
 * No hay contador de segundos pausados. Lo tentador era guardarlo en la
 * sesion para no leer la tabla de pausas, pero entonces el tiempo cobrado
 * tendria dos origenes —el contador y las filas— y el dia que uno se
 * actualice sin el otro, la mesa cobra lo que no jugaron. El tiempo se deriva
 * siempre de estas filas; el mapa del salon las trae con un eager load.
 *
 * Los totales en plata, en cambio, SI viven en columnas: el mapa muestra el
 * saldo de cada mesa ocupada y se repinta solo, y sumar renglones en cada
 * pintada seria una consulta por mesa. BilliardAccount es el unico que los
 * escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billiard_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hall_id')->constrained('billiard_halls')->cascadeOnDelete();

            // `restrict`: una cuenta sin mesa no se puede auditar.
            $table->foreignId('table_id')->constrained('billiard_tables')->restrictOnDelete();
            $table->foreignId('table_type_id')->nullable()
                ->constrained('billiard_table_types')->nullOnDelete();
            $table->foreignId('rate_id')->nullable()
                ->constrained('billiard_rates')->nullOnDelete();

            // A quien se le factura. Null = Consumidor Final, igual que el POS.
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();

            // El nombre con el que el salon llama a esa mesa: "Don Jorge",
            // "los del torneo". No es el cliente de la factura — casi nadie
            // da su cedula para jugar pool— pero sin el, cuatro mesas
            // abiertas son cuatro filas iguales.
            $table->string('customer_label', 120)->nullable();

            $table->unsignedTinyInteger('players')->default(2);
            // Partidas jugadas. Solo lo usan las tarifas por partida; en las
            // de tiempo queda como dato del reporte.
            $table->unsignedSmallInteger('games')->default(0);

            $table->dateTime('started_at');

            /**
             * Desde cuando cuenta el tramo que se esta cobrando ahora.
             *
             * Normalmente igual a started_at. Cambia cuando la mesa se
             * traslada: al pasar de una mesa de pool a una de tres bandas,
             * el tiempo ya jugado se congela en un renglon con la tarifa de
             * la mesa vieja y el reloj vuelve a arrancar aqui. Cobrarle todo
             * a la tarifa de la ultima mesa seria cobrarle de mas a quien se
             * cambio porque la primera estaba dañada.
             */
            $table->dateTime('time_anchor_at');

            $table->dateTime('ended_at')->nullable();

            /**
             * Si esta puesto, el reloj esta detenido desde esa hora.
             *
             * No hay status 'paused'. Un estado y una marca de tiempo que
             * dicen lo mismo se separan el dia que alguien actualice una y
             * olvide la otra, y entonces hay mesas que cobran tiempo
             * mientras la pantalla las muestra en pausa.
             */
            $table->dateTime('paused_at')->nullable();

            $table->string('status', 20)->default('open');   // open|closed|cancelled

            // Liquidacion del tiempo (se llena al cerrar)
            $table->unsignedInteger('time_minutes')->nullable();
            $table->unsignedInteger('free_minutes')->nullable();
            $table->unsignedInteger('charge_minutes')->nullable();
            $table->json('breakdown')->nullable();

            // Totales de la cuenta — los escribe BilliardAccount
            $table->decimal('time_total', 14, 2)->default(0);
            $table->decimal('consumption_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('sale_invoice_id')->nullable()
                ->constrained('sale_invoices')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'hall_id', 'status']);
            $table->index(['company_id', 'started_at']);
            $table->index(['table_id', 'status']);
        });

        Schema::create('billiard_session_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('billiard_sessions')->cascadeOnDelete();

            $table->string('kind', 20);          // time|product|service|adjustment
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->nullOnDelete();

            $table->date('charge_date');
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3)->default(1);

            // Precio AL PUBLICO (IVA incluido), misma convencion que el folio
            // del hotel y los extras del parqueadero: es el precio de la
            // carta, el que el cliente ve. La base se descompone al guardar.
            $table->decimal('unit_price', 14, 2)->default(0);

            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);

            $table->decimal('subtotal', 14, 2)->default(0);
            // Negativo en los ajustes: una cortesia es una rebaja de la cuenta.
            $table->decimal('total', 14, 2)->default(0);

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['session_id', 'kind']);
            $table->index(['company_id', 'charge_date']);
        });

        Schema::create('billiard_session_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('billiard_sessions')->cascadeOnDelete();

            $table->dateTime('paused_at');
            $table->dateTime('resumed_at')->nullable();
            $table->string('reason', 255)->nullable();

            $table->foreignId('paused_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('resumed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['session_id', 'paused_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billiard_session_pauses');
        Schema::dropIfExists('billiard_session_charges');
        Schema::dropIfExists('billiard_sessions');
    }
};
