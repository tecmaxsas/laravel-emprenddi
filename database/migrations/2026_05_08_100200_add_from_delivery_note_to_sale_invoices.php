<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linkea una factura de venta a la remisión que ya despachó el inventario.
 *
 * Cuando la factura se postea con from_delivery_note_id != null, el
 * SaleInvoiceEngine NO genera nuevos inventory_movements (porque ya pasó
 * en el despacho de la remisión) — solo arma asientos de venta y COGS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->foreignId('from_delivery_note_id')->nullable()
                ->after('cash_register_session_id')
                ->constrained('delivery_notes')->nullOnDelete();
            $table->index('from_delivery_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_delivery_note_id');
        });
    }
};
