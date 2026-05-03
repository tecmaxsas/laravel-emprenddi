<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('third_party_id')->constrained('third_parties')->restrictOnDelete();

            $table->string('prefix', 10)->default('FC');
            $table->unsignedBigInteger('number');
            $table->string('supplier_invoice_number', 50)->nullable();

            $table->date('date');
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);

            $table->string('currency', 3)->default('COP');
            $table->decimal('exchange_rate', 14, 6)->default(1);

            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);

            $table->enum('payment_status', ['pendiente', 'parcial', 'pagado', 'vencido', 'cancelada'])
                ->default('pendiente');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'third_party_id', 'payment_status']);
            $table->index(['company_id', 'status', 'date']);
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
