<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidación de prestaciones sociales: el pago efectivo de cesantías,
 * intereses, prima y vacaciones — y la liquidación definitiva al retiro.
 * Complementa las provisiones mensuales de la nómina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employment_contract_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 30); // prima | cesantias | intereses_cesantias | vacaciones | definitiva
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('worked_days')->default(0);

            $table->decimal('monthly_salary', 18, 2)->default(0);
            $table->decimal('base', 18, 2)->default(0);

            $table->decimal('amount_cesantias', 18, 2)->default(0);
            $table->decimal('amount_interest', 18, 2)->default(0);
            $table->decimal('amount_prima', 18, 2)->default(0);
            $table->decimal('amount_vacaciones', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);

            $table->string('status', 20)->default('draft'); // draft | paid
            $table->date('payment_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settlements');
    }
};
