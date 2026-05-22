<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Novedades de nómina — devengados y deducciones que se cargan
 * manualmente por empleado en un período (horas extra, bonificaciones,
 * préstamos, retención, etc.). El motor de liquidación las incorpora a
 * cada desprendible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_novelties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('concept_code', 40);
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payroll_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_novelties');
    }
};
