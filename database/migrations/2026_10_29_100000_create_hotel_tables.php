<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Hoteles — el inventario físico: hoteles, zonas, tipos y habitaciones.
 *
 * Se activa por empresa desde SuperAdmin (companies.active_modules incluye
 * 'hotel'). Sin esa activación el módulo entero es invisible, igual que
 * restaurante y parqueadero.
 *
 *   hotels            Cada hotel. Independiente de Sedes, como ParkingLot:
 *                     un hotel puede operarse sin ser una "tienda", pero
 *                     necesita una sede para facturar (la resolución DIAN
 *                     y el consecutivo viven en la sede).
 *   hotel_zones       Piso, torre, ala. Agrupa habitaciones en el mapa.
 *   hotel_room_types  Sencilla, Doble, Suite. **Aquí vive la tarifa**: en un
 *                     hotel el precio es del tipo, no de la habitación, y
 *                     ponerlo en cada habitación obliga a cambiar cincuenta
 *                     filas cuando sube la temporada.
 *   hotel_rooms       La habitación física.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);                      // H01, SEDE-NORTE
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();

            // Registro Nacional de Turismo. En Colombia todo prestador de
            // servicios turísticos debe tenerlo y exhibirlo, y va impreso en
            // la cuenta de cobro del huésped.
            $table->string('rnt', 30)->nullable();

            // Sede con la que se factura. Sin esto no hay resolución ni
            // consecutivo, así que el check-out no puede emitir factura.
            $table->foreignId('default_location_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            $table->string('default_invoice_kind', 20)->default('pos');  // pos | electronic

            // La hora pactada. No es decorativa: decide desde cuándo se puede
            // entregar una habitación y a partir de qué hora una salida tardía
            // genera cobro de noche adicional.
            $table->time('check_in_time')->default('15:00:00');
            $table->time('check_out_time')->default('12:00:00');

            $table->json('settings')->nullable();

            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('hotel_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            $table->string('name', 80);                      // "Piso 2", "Torre Sur"
            $table->string('code', 20)->nullable();
            $table->string('color', 9)->default('#3b82f6');   // para el mapa del front desk
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'name']);
            $table->index(['company_id', 'hotel_id', 'active']);
            $table->index(['company_id', 'display_order']);
        });

        Schema::create('hotel_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();

            $table->string('code', 30);                      // SEN, DOB, SUITE
            $table->string('name', 100);
            $table->text('description')->nullable();

            $table->unsignedTinyInteger('max_adults')->default(2);
            $table->unsignedTinyInteger('max_children')->default(0);

            /**
             * Tarifa por noche, PRECIO AL PÚBLICO (IVA incluido).
             *
             * Es la misma convención que usa el parqueadero al facturar: el
             * recepcionista cotiza lo que el huésped va a pagar, y la base
             * gravable se descompone al emitir la factura. Guardar la base y
             * sumarle el IVA después hace que el precio de la lista no
             * coincida con lo que dice el aviso de la recepción.
             */
            $table->decimal('base_rate', 14, 2)->default(0);

            // Lo que cuesta cada persona por encima de la ocupación base.
            $table->decimal('extra_person_rate', 14, 2)->default(0);

            $table->string('beds', 60)->nullable();          // "1 doble + 1 sencilla"
            $table->json('amenities')->nullable();           // ["wifi","tv","aire"]

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['hotel_id', 'code']);
            $table->index(['company_id', 'hotel_id', 'active']);
        });

        Schema::create('hotel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()
                ->constrained('hotel_zones')->nullOnDelete();

            // `restrict` a propósito: una habitación sin tipo no tiene tarifa
            // y no se podría cobrar. Mejor que el sistema lo impida que
            // descubrirlo en el check-out.
            $table->foreignId('room_type_id')->constrained('hotel_room_types')->restrictOnDelete();

            $table->string('number', 20);                    // 101, 2A, BUNG-3
            $table->string('name', 60)->nullable();          // "Suite vista al mar"
            $table->string('floor', 30)->nullable();

            /**
             * Un solo estado, no dos.
             *
             * Un PMS grande separa el estado de recepción del de camarería
             * (ocupada-sucia, libre-limpia…). Acá el ciclo es lineal y el
             * estado lo refleja: ocupada → (check-out) aseo → disponible.
             * Con dos columnas habría que mantener las dos sincronizadas y la
             * recepción terminaría mirando la que no corresponde.
             */
            $table->string('status', 20)->default('available');

            $table->unsignedTinyInteger('max_occupancy')->default(2);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['hotel_id', 'number']);
            $table->index(['hotel_id', 'status']);
            $table->index(['company_id', 'hotel_id', 'zone_id']);
            $table->index(['company_id', 'hotel_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_rooms');
        Schema::dropIfExists('hotel_room_types');
        Schema::dropIfExists('hotel_zones');
        Schema::dropIfExists('hotels');
    }
};
