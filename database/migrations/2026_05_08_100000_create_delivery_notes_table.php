<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remisiones — documento de despacho que mueve inventario sin contabilizar
 * venta. Se factura después; la factura asociada no vuelve a descargar
 * inventario (ya pasó), solo asienta la venta y el COGS desde los
 * movimientos existentes.
 *
 * Status:
 *   draft       → en preparación, sin movimiento de inventario aún
 *   dispatched  → salió del almacén, ya descargó inventario
 *   delivered   → cliente confirmó recepción
 *   cancelled   → cancelada (solo desde draft, sin reversa de inventario)
 *   billed      → ya facturada (link en billed_at_sale_invoice_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('third_party_id')->constrained('third_parties')->restrictOnDelete();

            $table->string('prefix', 10)->default('REM');
            $table->unsignedBigInteger('number');

            $table->date('date');

            // Datos de transporte
            $table->string('carrier', 150)->nullable();
            $table->string('vehicle_plate', 30)->nullable();
            $table->string('driver_name', 150)->nullable();
            $table->string('destination_address', 250)->nullable();

            $table->enum('status', ['draft', 'dispatched', 'delivered', 'cancelled', 'billed'])
                ->default('draft');

            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('dispatched_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();

            $table->foreignId('billed_at_sale_invoice_id')->nullable()
                ->constrained('sale_invoices')->nullOnDelete();
            $table->timestamp('billed_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('seller_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'status', 'date']);
            $table->index(['company_id', 'third_party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
