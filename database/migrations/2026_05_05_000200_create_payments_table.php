<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Polimórfico: apunta a PurchaseInvoice o (futuro) SalesInvoice
            $table->morphs('paymentable');

            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();

            $table->date('date');
            $table->decimal('amount', 18, 2);

            $table->enum('payment_method', [
                'cash',           // Efectivo
                'bank_transfer',  // Transferencia bancaria
                'check',          // Cheque
                'credit_card',    // Tarjeta de crédito
                'debit_card',     // Tarjeta débito
                'electronic',     // PSE / Wompi / pagos electrónicos
                'credit_note',    // Nota crédito aplicada
                'other',
            ])->default('cash');

            $table->foreignId('account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();

            $table->string('reference', 100)->nullable();
            $table->text('description')->nullable();

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'third_party_id']);
            $table->index(['company_id', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
