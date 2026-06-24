<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesion de parqueo (un vehiculo entra y eventualmente sale).
 *
 * El status describe el ciclo de vida:
 *   active        vehiculo dentro del parqueadero
 *   closed        salio y se cobro normalmente
 *   lost_ticket   salio con cobro por ticket perdido
 *   cancelled     anulada manualmente (con motivo)
 *
 * Las cuentas (sale_invoice_id) y el medio de pago se llenan en el commit 5
 * cuando se conecta con SaleInvoice/POS/DIAN — aqui solo dejamos los campos
 * preparados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parking_lot_id')->constrained('parking_lots')->cascadeOnDelete();
            $table->foreignId('vehicle_type_id')->nullable()
                ->constrained('vehicle_types')->nullOnDelete();

            // Snapshot de la tarifa aplicada al cobrar (puede ser null hasta el checkout).
            // Si la tarifa se modifica/elimina, mantenemos la referencia historica del JSON.
            $table->foreignId('rate_id')->nullable()
                ->constrained('parking_rates')->nullOnDelete();

            $table->string('plate', 20);                  // placa del vehiculo
            $table->string('space_code', 30)->nullable(); // espacio asignado (commit 3)
            $table->string('entry_photo_path')->nullable();  // commit 8
            $table->string('exit_photo_path')->nullable();   // commit 8

            $table->dateTime('entry_at');
            $table->dateTime('exit_at')->nullable();

            $table->string('status', 20)->default('active');

            // Calculo (poblado al checkout)
            $table->unsignedInteger('total_minutes')->nullable();
            $table->unsignedInteger('free_minutes')->nullable();
            $table->unsignedInteger('charge_minutes')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('cap_applied')->default(false);
            $table->json('breakdown')->nullable();

            // Cobro y facturacion (commit 5 los completa)
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

            $table->index(['company_id', 'parking_lot_id', 'status']);
            $table->index(['company_id', 'plate', 'status']);
            $table->index(['company_id', 'entry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_sessions');
    }
};
