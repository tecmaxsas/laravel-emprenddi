<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo Parqueaderos — tablas base.
 *
 * Diseñado para activarse a nivel sistema por SuperAdmin
 * (companies.active_modules incluye 'parking'). Sin esa activacion el
 * modulo es invisible.
 *
 *  vehicle_types     Catalogo por empresa: carro, moto, camion, bici...
 *  parking_lots      Cada parqueadero fisico (independiente de Sedes).
 *  parking_rates     Tarifa con JSON de reglas (motor de tarifas).
 *
 * Las sesiones (parking_sessions) y espacios (parking_spaces) se agregan
 * en commits posteriores cuando se construye la UI de entrada/salida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);                       // CAR, MOTO, BIKE, TRUCK
            $table->string('name', 80);                       // "Carro particular", "Moto", ...
            $table->string('icon', 50)->nullable();           // emoji o nombre heroicon
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('parking_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);                       // P01, NORTE, AEROP...
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();

            // Capacidad total opcional (alertas de aforo)
            $table->unsignedInteger('total_capacity')->nullable();

            // Settings adicionales del parqueadero como JSON: aviso de tarifas
            // visible (SIC), parqueos para discapacidad, modo offline, etc.
            $table->json('settings')->nullable();

            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('parking_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parking_lot_id')->constrained('parking_lots')->cascadeOnDelete();

            // null = aplica a todos los tipos del parqueadero (default)
            $table->foreignId('vehicle_type_id')->nullable()
                ->constrained('vehicle_types')->nullOnDelete();

            $table->string('name', 150);
            $table->string('kind', 20)->default('regular');   // regular | membership | corporate | event | accessibility

            // Configuracion en JSON — el motor (ParkingRateEngine) lo
            // interpreta. Schema documentado en RateEngine.
            $table->json('config');

            // Vigencia (null = sin tope). Util para tarifas estacionales.
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            // Prioridad cuando hay varias tarifas activas para el mismo
            // (lot, vehicle_type). Mayor priority gana.
            $table->unsignedSmallInteger('priority')->default(0);

            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'parking_lot_id', 'vehicle_type_id', 'active'], 'pr_lookup_idx');
            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_rates');
        Schema::dropIfExists('parking_lots');
        Schema::dropIfExists('vehicle_types');
    }
};
