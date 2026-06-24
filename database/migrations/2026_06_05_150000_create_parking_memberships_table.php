<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensualidades y convenios corporativos.
 *
 * Una sola tabla cubre los dos casos:
 *   kind=individual   un cliente, una placa, una tarifa mensual
 *   kind=corporate    una empresa (third_party), varias placas, facturacion
 *                     centralizada
 *
 * plates es un JSON array de placas autorizadas. En individual lleva una
 * sola; en corporate lleva muchas. La busqueda al checkin recorre el array
 * (whereJsonContains) para detectar si la placa esta cubierta.
 *
 * Tambien agregamos parking_sessions.parking_membership_id (nullable) para
 * marcar una sesion como cubierta por mensualidad — su monto queda en 0 y
 * no genera cobro al cliente final (la mensualidad ya se facturo aparte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parking_lot_id')->constrained('parking_lots')->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('third_parties')->restrictOnDelete();
            $table->foreignId('vehicle_type_id')->nullable()
                ->constrained('vehicle_types')->nullOnDelete();

            // 'individual' | 'corporate'
            $table->string('kind', 20)->default('individual');

            // Placas autorizadas. JSON array: ['ABC123'] o ['ABC123', 'XYZ789', ...]
            $table->json('plates');

            // Identificador legible del convenio (ej. "Convenio Acme 2026")
            $table->string('name', 150);

            $table->date('start_date');
            $table->date('end_date');

            // Tarifa que se cobra periodicamente (ej. mensual). Para corporate
            // suele ser tarifa total del convenio. La facturacion la maneja
            // el modulo de POS/factura separado.
            $table->decimal('amount', 14, 2)->default(0);

            // active | expired | cancelled
            $table->string('status', 20)->default('active');

            // Auto-renovacion al vencer (futuro: cron lo procesa)
            $table->boolean('auto_renew')->default(false);

            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'parking_lot_id', 'status']);
            $table->index(['company_id', 'third_party_id']);
            $table->index(['company_id', 'end_date']);
        });

        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->foreignId('parking_membership_id')->nullable()
                ->after('rate_id')
                ->constrained('parking_memberships')->nullOnDelete();
            $table->index(['parking_membership_id']);
        });
    }

    public function down(): void
    {
        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parking_membership_id');
        });
        Schema::dropIfExists('parking_memberships');
    }
};
