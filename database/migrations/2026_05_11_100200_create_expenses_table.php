<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos — salidas de efectivo/banco operativas (papelería, café, transporte,
 * servicios públicos pequeños, etc.). A diferencia de PurchaseInvoice no
 * tiene proveedor formal ni payment_status: el gasto siempre es pagado al
 * momento de registrarlo (debit gasto, credit caja/banco).
 *
 * Requiere sesión de caja abierta en la mayoría de flujos (gate en app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()
                ->constrained('cash_register_sessions')->nullOnDelete();
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();

            // Cuenta contable de GASTO (5XXX). El payment_account es la cuenta
            // de DONDE sale el dinero (caja, banco).
            $table->foreignId('expense_account_id')
                ->constrained('accounts')->restrictOnDelete();
            $table->foreignId('payment_account_id')
                ->constrained('accounts')->restrictOnDelete();

            $table->string('prefix', 10)->default('EXP');
            $table->unsignedBigInteger('number');

            $table->date('date');
            $table->string('concept', 200);  // p. ej. "Café para oficina"
            $table->text('description')->nullable();

            // Montos. Si la factura del gasto tiene IVA descontable, el usuario
            // captura tax_id y se calcula tax_amount.
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->foreignId('tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);

            $table->string('payment_method', 30)->default('cash');
            $table->string('reference', 100)->nullable();
            $table->string('supplier_invoice_number', 50)->nullable();  // # de factura del proveedor

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
            $table->index(['company_id', 'status', 'date']);
            $table->index(['company_id', 'cash_register_session_id'], 'expenses_company_session_idx');
            $table->index(['company_id', 'third_party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
