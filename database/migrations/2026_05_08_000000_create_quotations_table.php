<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones — propuestas comerciales sin valor fiscal.
 *
 * NO genera asientos contables ni descarga inventario. Tiene fecha de
 * validez (valid_until) que dispara expiración. Si el cliente la aprueba,
 * se convierte en SaleInvoice draft mediante la acción "Convertir a factura"
 * — el invoice resultante hereda las líneas y el cliente.
 *
 * status:
 *   draft      → en edición
 *   sent       → enviada al cliente
 *   approved   → aprobada (lista para convertir)
 *   rejected   → cliente la rechazó
 *   expired    → valid_until vencida sin aprobación
 *   converted  → ya generó la factura (converted_to_sale_invoice_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('third_party_id')->constrained('third_parties')->restrictOnDelete();

            $table->string('prefix', 10)->default('COT');
            $table->unsignedBigInteger('number');

            $table->date('date');
            $table->date('valid_until')->nullable();

            $table->string('currency', 3)->default('COP');
            $table->decimal('exchange_rate', 14, 6)->default(1);

            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);

            $table->enum('status', ['draft', 'sent', 'approved', 'rejected', 'expired', 'converted'])
                ->default('draft');

            $table->foreignId('converted_to_sale_invoice_id')->nullable()
                ->constrained('sale_invoices')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('seller_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'status', 'date']);
            $table->index(['company_id', 'third_party_id']);
            $table->index(['company_id', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
