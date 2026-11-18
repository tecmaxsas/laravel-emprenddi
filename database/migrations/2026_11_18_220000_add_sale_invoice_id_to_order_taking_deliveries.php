<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada despacho con la factura de venta que lo cobra.
 *
 * Un pedido ya no da una sola factura: cada despacho se factura aparte, por
 * lo que salio en el. Esta columna impide facturar dos veces la misma
 * remision y permite ir del despacho a su factura.
 *
 * La columna sale_invoice_id del pedido se conserva para los pedidos que se
 * facturaron completos antes de este cambio.
 *
 * nullOnDelete: si la factura se elimina, el despacho queda otra vez sin
 * facturar en lugar de arrastrar una referencia rota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_taking_deliveries', function (Blueprint $table) {
            $table->foreignId('sale_invoice_id')
                ->nullable()
                ->after('notes')
                ->constrained('sale_invoices')
                ->nullOnDelete();

            $table->index('sale_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_taking_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_invoice_id');
        });
    }
};
