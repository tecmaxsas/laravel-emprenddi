<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza el pedido con la factura de venta en que se convirtio.
 *
 * El pedido es un documento operativo: no mueve inventario ni contabilidad.
 * La factura es donde todo eso aterriza, y un pedido solo puede dar una: esta
 * columna es lo que impide facturarlo dos veces y lo que permite ir del uno al
 * otro.
 *
 * nullOnDelete: si la factura se elimina, el pedido queda otra vez sin
 * facturar en lugar de arrastrar una referencia rota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->foreignId('sale_invoice_id')
                ->nullable()
                ->after('payment_status')
                ->constrained('sale_invoices')
                ->nullOnDelete();

            $table->index('sale_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_invoice_id');
        });
    }
};
