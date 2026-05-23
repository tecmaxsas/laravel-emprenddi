<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado lógico para tablas referenciadas por otras con restrictOnDelete.
 * Sin soft delete, intentar eliminar un registro con historial tira un
 * error de llave foránea (mismo bug que ocurrió con las impresoras).
 *
 *   accounts  ← journal_entry_lines, expenses, inventory_adjustments,
 *               inventory_openings, fixed_assets (×3)
 *   employees ← payroll_slips, payroll_settlements
 *   users     ← cash_register_sessions.cashier_user_id
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
