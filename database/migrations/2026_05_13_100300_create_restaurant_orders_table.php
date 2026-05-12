<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orden activa del restaurante: representa la cuenta de una mesa o un
 * pedido de delivery mientras está abierta. Al cerrarla se genera una
 * SaleInvoice (o varias si hay división de cuenta).
 *
 *  status flujo típico:
 *    open → in_kitchen → served → billing → closed
 *    open → cancelled
 *
 *  is_delivery=true: table_id es null; delivery_metadata trae
 *  address/phone/driver/etc.
 *
 *  Propina: tip_amount sobre subtotal pre-impuestos (decision regulatoria
 *  Colombia: la propina nunca se gravay nunca lleva IVA).
 *  total = subtotal + tax_total - discount_total + tip_amount
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()
                ->constrained('cash_register_sessions')->nullOnDelete();

            // Mesa o delivery
            $table->foreignId('table_id')->nullable()
                ->constrained('restaurant_tables')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()
                ->constrained('restaurant_service_zones')->nullOnDelete();
            $table->boolean('is_delivery')->default(false);
            $table->boolean('is_takeaway')->default(false);  // para llevar (pickup)

            // Personal
            $table->foreignId('server_user_id')->nullable()
                ->constrained('users')->nullOnDelete();  // mesero
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();  // cliente

            // Identificación
            $table->string('prefix', 10)->default('ORD');
            $table->unsignedBigInteger('number');
            $table->unsignedSmallInteger('guests')->default(1);  // # comensales

            $table->enum('status', [
                'open', 'in_kitchen', 'served', 'billing', 'closed', 'cancelled',
            ])->default('open');

            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Totales (recalculados desde las líneas)
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('tip_amount', 18, 2)->default(0);  // sobre subtotal pre-impuestos
            $table->decimal('tip_percentage', 5, 2)->default(0);  // si se calculó como %
            $table->decimal('delivery_fee', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);

            $table->jsonb('delivery_metadata')->nullable();  // {address, phone, driver_id, eta_min, ...}
            $table->text('notes')->nullable();

            // Cuando se cierra y factura — link al / a los SaleInvoice
            // generados (puede ser varios si hubo división).
            $table->jsonb('invoice_ids')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'location_id', 'status']);
            $table->index(['company_id', 'table_id', 'status']);
            $table->index(['company_id', 'server_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_orders');
    }
};
