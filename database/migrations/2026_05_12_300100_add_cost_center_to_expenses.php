<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El gasto se imputa típicamente a un centro de costo único (el gasto
 * pertenece a un área/proyecto). Lo agregamos a nivel header — el engine
 * lo propaga a la línea de DR cuenta de gasto en el JournalEntry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()
                ->after('expense_account_id')
                ->constrained('cost_centers')->nullOnDelete();
            $table->index(['company_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });
    }
};
