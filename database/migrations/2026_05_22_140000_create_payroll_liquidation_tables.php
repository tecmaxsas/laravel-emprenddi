<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina — capa 2: liquidación. Parámetros anuales (SMMLV, auxilio de
 * transporte), períodos de nómina y los desprendibles (slips) con su
 * detalle de devengados y deducciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Parámetros legales por año (los define el Gobierno cada año).
        Schema::create('payroll_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('smmlv', 18, 2);
            $table->decimal('transport_allowance', 18, 2);
            $table->decimal('uvt', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'year']);
        });

        // Período de nómina (mensual / quincenal).
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('frequency', 20)->default('mensual');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('payment_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('liquidated_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // Desprendible de pago por empleado dentro de un período.
        Schema::create('payroll_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employment_contract_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('worked_days')->default(30);
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('salary_earned', 18, 2)->default(0);
            $table->decimal('transport_allowance', 18, 2)->default(0);

            $table->decimal('total_earnings', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('net_pay', 18, 2)->default(0);

            // Deducciones de ley al empleado.
            $table->decimal('employee_health', 18, 2)->default(0);
            $table->decimal('employee_pension', 18, 2)->default(0);
            $table->decimal('solidarity_fund', 18, 2)->default(0);

            // Aportes del empleador (costo de la empresa).
            $table->decimal('employer_health', 18, 2)->default(0);
            $table->decimal('employer_pension', 18, 2)->default(0);
            $table->decimal('employer_arl', 18, 2)->default(0);
            $table->decimal('employer_caja', 18, 2)->default(0);
            $table->decimal('employer_sena', 18, 2)->default(0);
            $table->decimal('employer_icbf', 18, 2)->default(0);

            // Provisiones (prestaciones sociales).
            $table->decimal('prov_cesantias', 18, 2)->default(0);
            $table->decimal('prov_interest', 18, 2)->default(0);
            $table->decimal('prov_prima', 18, 2)->default(0);
            $table->decimal('prov_vacaciones', 18, 2)->default(0);

            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
        });

        // Detalle (líneas) de cada desprendible.
        Schema::create('payroll_slip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_slip_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // earning | deduction
            $table->string('concept_code', 30);
            $table->string('concept_name', 120);
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slip_lines');
        Schema::dropIfExists('payroll_slips');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('payroll_parameters');
    }
};
