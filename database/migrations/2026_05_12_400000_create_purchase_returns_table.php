<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones a proveedor. Espejo de PurchaseInvoice — al postearse
 * reduce el saldo CxP, el IVA descontable, y el inventario:
 *   DR  CxP proveedor   (220505)
 *   CR  Inventario      (cuenta por producto)
 *   CR  IVA descontable (240810)
 *
 * Puede o no apuntar a una purchase_invoice original (purchase_invoice_id
 * nullable). Si apunta, sirve como trazabilidad y permite recuperar
 * costo unitario y tax rate de origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('third_party_id')->constrained('third_parties')->restrictOnDelete();

            $table->foreignId('purchase_invoice_id')->nullable()
                ->constrained('purchase_invoices')->nullOnDelete();

            $table->string('prefix', 10)->default('DEV');
            $table->unsignedBigInteger('number');

            $table->date('date');
            $table->text('reason')->nullable();

            $table->decimal('subtotal', 18, 2)->default(0);
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

            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'third_party_id', 'status']);
            $table->index(['company_id', 'status', 'date']);
            $table->index(['company_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
