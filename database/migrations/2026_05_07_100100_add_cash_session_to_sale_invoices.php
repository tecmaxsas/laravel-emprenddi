<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linkea cada factura POS a la sesión de caja en la que se generó.
 * Nullable: facturas creadas desde el SaleInvoiceResource (no POS) no tienen
 * sesión asociada — solo aplica al flujo POS.
 *
 * Permite calcular total_sales, payment_breakdown y closing_expected
 * agregando las facturas de la sesión en lugar de hacer queries pesados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->foreignId('cash_register_session_id')->nullable()
                ->after('seller_user_id')
                ->constrained('cash_register_sessions')->nullOnDelete();
            $table->index('cash_register_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_session_id');
        });
    }
};
