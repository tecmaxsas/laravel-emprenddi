<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espacios fisicos dentro de cada parqueadero.
 *
 *  status:
 *    free          libre para asignar
 *    occupied      ocupado por una sesion activa
 *    reserved      reservado (commit futuro: reservas via app/convenio)
 *    maintenance   fuera de servicio
 *
 *  is_accessibility = espacio prioritario por ley colombiana (discapacidad)
 *
 * Tambien agregamos parking_space_id (nullable) a parking_sessions para
 * trazar quien ocupa cada espacio. El campo space_code anterior queda
 * como denormalizacion (snapshot del codigo para reportes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parking_lot_id')->constrained('parking_lots')->cascadeOnDelete();

            // Restringido a un tipo de vehiculo (null = cualquier tipo)
            $table->foreignId('vehicle_type_id')->nullable()
                ->constrained('vehicle_types')->nullOnDelete();

            $table->string('code', 30);                  // A-01, B-12, MOTO-03...
            $table->string('zone', 50)->nullable();      // "Piso 1", "Sotano", "Norte"
            $table->string('status', 20)->default('free');
            $table->boolean('is_accessibility')->default(false);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['parking_lot_id', 'code']);
            $table->index(['parking_lot_id', 'status']);
            $table->index(['company_id', 'parking_lot_id', 'zone']);
        });

        // Conecta sesion <-> espacio
        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->foreignId('parking_space_id')->nullable()
                ->after('vehicle_type_id')
                ->constrained('parking_spaces')->nullOnDelete();
            $table->index(['parking_lot_id', 'parking_space_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parking_space_id');
        });
        Schema::dropIfExists('parking_spaces');
    }
};
