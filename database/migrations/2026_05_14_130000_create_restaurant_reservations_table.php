<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservaciones: cliente apartó una mesa para X hora. Cuando llega se
 * marca "seated" y se asocia a la Order que abre la mesa.
 *
 *  Estados:
 *   pending   → recién creada
 *   confirmed → confirmada por el cliente / host
 *   seated    → llegó y se sentó (linkeada a una Order)
 *   no_show   → no apareció
 *   cancelled → cancelada
 *   completed → terminó el servicio (auto cuando se cierra la order)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()
                ->constrained('restaurant_tables')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()
                ->constrained('restaurant_service_zones')->nullOnDelete();

            $table->string('customer_name', 100);
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 100)->nullable();

            $table->timestamp('reserved_for');
            $table->unsignedSmallInteger('duration_minutes')->default(90);
            $table->unsignedTinyInteger('guests')->default(2);

            $table->enum('status', ['pending', 'confirmed', 'seated', 'no_show', 'cancelled', 'completed'])
                ->default('pending');
            $table->text('notes')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Linkea con la Order abierta cuando el cliente se sienta
            $table->foreignId('seated_order_id')->nullable()
                ->constrained('restaurant_orders')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['location_id', 'reserved_for', 'status']);
            $table->index('reserved_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_reservations');
    }
};
