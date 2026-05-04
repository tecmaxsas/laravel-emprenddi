<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos de retenciones al header de la factura de venta.
 *
 *   retention_total = suma de las líneas en sale_invoice_retentions
 *   net_payable     = total - retention_total = lo que el cliente realmente paga
 *
 * El balance y payment_status se calculan contra net_payable (no contra total),
 * porque las retenciones NO son un saldo pendiente, son anticipos de impuesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->decimal('retention_total', 18, 2)->default(0)->after('tax_total');
            $table->decimal('net_payable', 18, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropColumn(['retention_total', 'net_payable']);
        });
    }
};
