<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métodos de pago configurables por empresa.
 *
 * code es el identificador estable que se persiste en payments.payment_method
 * (e.g. 'cash', 'credit_card', 'bank_transfer'). Los 8 métodos estándar se
 * siembran automáticamente con DefaultPaymentMethodsSeeder en cada deploy
 * para que las empresas existentes los tengan listos.
 *
 * account_id es la cuenta default de caja/banco asociada — el POS y el
 * PaymentsRelationManager la pre-seleccionan al elegir el método.
 *
 * requires_reference: cuando true, la UI muestra y exige el campo "referencia"
 * (típico para transferencia, cheque, PSE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            // type: agrupador conceptual (cash, card, transfer, ...). El code es el id estable.
            $table->string('type', 30);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('requires_reference')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
