<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo Citas / Agendamiento (opcional, activable en settings.appointments.*).
 *
 * Una cita agenda un servicio para un cliente con un profesional (empleado)
 * asignado, en una franja horaria. Al atenderla se puede precargar el POS
 * para cobrar; la factura resultante queda enlazada en sale_invoice_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Cliente (third_party con is_customer). Nullable: se puede agendar
            // sin cliente fijo y completarlo despues, salvo que el setting
            // require_client este activo (validado en UI).
            $table->foreignId('third_party_id')->nullable()->constrained('third_parties')->nullOnDelete();

            // Profesional que atiende (empleado). Nullable = sin asignar.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // Servicio agendado (product type=service). Nullable = cita generica.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // Factura generada al cobrar la cita (integracion POS).
            $table->foreignId('sale_invoice_id')->nullable()->constrained('sale_invoices')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // scheduled | confirmed | attended | completed | cancelled | no_show
            $table->string('status', 20)->default('scheduled');

            // Snapshot del precio del servicio al agendar (para mostrar/cobrar).
            $table->decimal('price', 16, 2)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'starts_at']);
            $table->index(['company_id', 'employee_id', 'starts_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
