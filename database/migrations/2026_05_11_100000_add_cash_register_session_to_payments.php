<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linkea cada pago (de SaleInvoice / PurchaseInvoice / Expense) a la sesión
 * de caja en que se efectuó. Permite que el cierre de caja agregue ingresos
 * y egresos de cualquier flujo, no solo de ventas POS.
 *
 * Nullable: pagos hechos fuera de turno (admin reconciliando) no requieren
 * sesión. La obligatoriedad se enforza en la capa de aplicación, no en la BD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_register_session_id')->nullable()
                ->after('account_id')
                ->constrained('cash_register_sessions')->nullOnDelete();
            $table->index(['company_id', 'cash_register_session_id'], 'payments_company_session_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_company_session_idx');
            $table->dropConstrainedForeignId('cash_register_session_id');
        });
    }
};
