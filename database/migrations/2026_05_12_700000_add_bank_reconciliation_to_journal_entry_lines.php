<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación bancaria por línea: el contador marca cada línea contable
 * que toca una cuenta bancaria como "ya aparece en el extracto del
 * banco". El sistema muestra la diferencia entre saldo en libros y el
 * saldo del extracto declarado por el contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->boolean('bank_reconciled')->default(false)->after('credit');
            $table->timestamp('bank_reconciled_at')->nullable()->after('bank_reconciled');
            $table->foreignId('bank_reconciled_by_user_id')->nullable()
                ->after('bank_reconciled_at')
                ->constrained('users')->nullOnDelete();
            $table->string('bank_reference', 100)->nullable()->after('bank_reconciled_by_user_id');

            $table->index(['account_id', 'bank_reconciled'], 'jel_account_reconciled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('jel_account_reconciled_idx');
            $table->dropConstrainedForeignId('bank_reconciled_by_user_id');
            $table->dropColumn(['bank_reconciled', 'bank_reconciled_at', 'bank_reference']);
        });
    }
};
