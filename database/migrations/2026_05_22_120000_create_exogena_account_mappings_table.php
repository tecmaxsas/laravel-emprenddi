<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Información exógena — mapeo de cuentas del PUC a los conceptos de cada
 * formato DIAN (1001-1012). Con este mapeo el motor de exógena agrega
 * los movimientos/saldos del año por tercero y concepto.
 *
 * Una cuenta se mapea a un único concepto dentro de cada formato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exogena_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('format_code', 4);
            $table->string('concept_code', 12);
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'format_code', 'account_id'], 'exogena_map_unique');
            $table->index(['company_id', 'format_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exogena_account_mappings');
    }
};
