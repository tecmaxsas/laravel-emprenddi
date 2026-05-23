<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets de garantía / RMA. Cada fila = una reclamación de un cliente
 * sobre un producto que vendimos. Permite:
 *
 *  - Buscar por serial o factura el equipo a reparar
 *  - Estado de servicio (recibida → en_revision → en_reparacion →
 *    resuelta | reemplazada | rechazada → entregada)
 *  - Plazo de garantía calculado desde products.warranty_days
 *  - Asignación a técnico interno
 *  - RMA propio (string, unique por empresa)
 *
 * El historial detallado vive en warranty_events (tabla aparte) para
 * que se puedan listar cambios, comentarios y adjuntos sin inflar
 * esta tabla con bitácoras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            // Numeración propia (consistente con compras/ventas)
            $table->string('prefix', 10)->default('GAR');
            $table->unsignedBigInteger('number');
            $table->string('rma_number', 50)->nullable();

            // Relación con el cliente y el equipo
            $table->foreignId('third_party_id')->constrained('third_parties')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->foreignId('sale_invoice_id')->nullable()->constrained('sale_invoices')->nullOnDelete();

            // Fechas
            $table->date('claim_date'); // día que el cliente trae el equipo
            $table->date('expiration_date')->nullable(); // calculado: venta + warranty_days
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Estado del ticket
            $table->enum('status', [
                'received',         // recibida en sede
                'in_review',        // en revisión técnica
                'in_repair',        // en reparación
                'resolved',         // reparada, lista para entregar
                'replaced',         // se reemplazó por nueva unidad
                'rejected',         // garantía no aplica
                'delivered',        // entregada al cliente
            ])->default('received');

            // Personas
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Contenido
            $table->text('reason'); // descripción del problema reportado
            $table->text('resolution_notes')->nullable();
            $table->json('attachments')->nullable(); // paths en storage public

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->unique(['company_id', 'rma_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'expiration_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
