<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas crédito y débito sobre facturas de venta.
 *
 * Tabla unificada con type=credit|debit. Lo único que cambia entre ambas es
 * el signo del asiento contable (NC revierte, ND aumenta) y el
 * type_document_id DIAN (4 para NC, 5 para ND). El resto de campos y flujo
 * es idéntico, así que evita duplicar tabla.
 *
 * sale_invoice_id es obligatorio: DIAN exige billing_reference apuntando a
 * la factura original (incluso para NC de anulación).
 *
 * affects_inventory (solo aplica a NC): si true, genera movimiento
 * return_from_customer al postear (entra inventario al stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('third_party_id')->constrained('third_parties')->restrictOnDelete();
            $table->foreignId('sale_invoice_id')->constrained('sale_invoices')->restrictOnDelete();

            $table->enum('type', ['credit', 'debit']);

            $table->string('prefix', 10);
            $table->unsignedBigInteger('number');

            $table->date('date');

            // Motivo DIAN (códigos oficiales 1-5 para NC, 1-4 para ND)
            $table->unsignedSmallInteger('reason_code');
            $table->string('reason_description', 250)->nullable();

            $table->boolean('affects_inventory')->default(false);

            $table->string('currency', 3)->default('COP');
            $table->decimal('exchange_rate', 14, 6)->default(1);

            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            // DIAN
            $table->foreignId('dian_resolution_id')->nullable()
                ->constrained('dian_resolutions')->nullOnDelete();
            $table->string('dian_status', 20)->nullable();
            $table->string('dian_status_code', 10)->nullable();
            $table->string('cufe', 100)->nullable();
            $table->text('qr_url')->nullable();
            $table->json('dian_response')->nullable();
            $table->text('dian_error_message')->nullable();
            $table->timestamp('dian_sent_at')->nullable();

            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'type', 'prefix', 'number']);
            $table->index(['company_id', 'sale_invoice_id']);
            $table->index(['company_id', 'type', 'status', 'date']);
            $table->index(['company_id', 'dian_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_debit_notes');
    }
};
