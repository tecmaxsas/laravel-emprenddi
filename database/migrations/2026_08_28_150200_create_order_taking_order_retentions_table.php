<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones aplicadas a un pedido. Un pedido puede llevar varias
 * (tipicamente ReteFuente + ReteIVA + ReteICA).
 *
 * Espejo de sale_invoice_retentions: guarda el snapshot del Tax al momento de
 * tomar el pedido y no se actualiza si despues editan la tarifa, para que el
 * documento siga cuadrando con lo que se le dijo al cliente ese dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_taking_order_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('order_taking_orders')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();

            $table->string('tax_code', 30);
            $table->string('tax_name', 150);
            $table->string('tax_type', 30); // income_withholding | vat_withholding | ica_withholding
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('rate', 7, 4)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_taking_order_retentions');
    }
};
