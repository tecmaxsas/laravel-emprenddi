<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina — capa 1: empleados y contratos laborales. Es la base sobre la
 * que después se liquida la nómina y se emite el documento de nómina
 * electrónica a la DIAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Vínculo opcional con el tercero contable (se usa al postear nómina).
            $table->foreignId('third_party_id')->nullable()->constrained('third_parties')->nullOnDelete();

            $table->string('document_type', 20)->default('cc');
            $table->string('document_number', 30);
            $table->string('first_name', 60);
            $table->string('middle_name', 60)->nullable();
            $table->string('last_name', 60);
            $table->string('second_last_name', 60)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();

            // Forma de pago del sueldo.
            $table->string('payment_method', 20)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_account_type', 20)->nullable();
            $table->string('bank_account_number', 40)->nullable();

            // Seguridad social y parafiscales.
            $table->string('eps_name', 100)->nullable();
            $table->string('pension_fund_name', 100)->nullable();
            $table->string('arl_name', 100)->nullable();
            $table->string('compensation_fund_name', 100)->nullable();

            $table->date('hire_date');
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('contract_type', 30)->default('indefinido');
            $table->string('salary_type', 20)->default('ordinario');
            $table->string('position', 100);
            $table->decimal('salary', 18, 2)->default(0);
            $table->boolean('transport_allowance_applies')->default(true);
            $table->string('payment_frequency', 20)->default('mensual');

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('work_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('risk_class', 10)->nullable();

            $table->string('status', 20)->default('active');
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_contracts');
        Schema::dropIfExists('employees');
    }
};
