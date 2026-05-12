<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las compras también pasan por la sesión de caja del operador. Pago en
 * efectivo de una compra = egreso del turno. Nullable porque admins pueden
 * registrar compras históricas sin sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->foreignId('cash_register_session_id')->nullable()
                ->after('location_id')
                ->constrained('cash_register_sessions')->nullOnDelete();
            $table->index(['company_id', 'cash_register_session_id'], 'purchase_inv_company_session_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropIndex('purchase_inv_company_session_idx');
            $table->dropConstrainedForeignId('cash_register_session_id');
        });
    }
};
