<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones que el cliente nos hace al pagar la factura.
 * Una factura puede tener varias (típicamente: ReteFuente + ReteIVA + ReteICA).
 *
 * tax_id apunta a un Tax con type IN (income_withholding, vat_withholding,
 * ica_withholding). La cuenta contable donde se debita el anticipo viene de
 * tax->sale_account_id (1355xx para ReteFuente cobrada, 135517 para ReteIVA, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_invoice_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            // Snapshot del Tax al momento de generar la factura — no se actualiza
            // si después editan el Tax, para preservar histórico contable.
            $table->string('tax_code', 30);
            $table->string('tax_name', 150);
            $table->string('tax_type', 30); // income_withholding | vat_withholding | ica_withholding
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('rate', 7, 4)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index('sale_invoice_id');
            $table->index('tax_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_invoice_retentions');
    }
};
