<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columna de valor del mapeo de exógena: para los formatos 1001/1007 una
 * cuenta puede alimentar la columna de pago, de IVA o de retención. El
 * resto de formatos usa 'base'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exogena_account_mappings', function (Blueprint $table) {
            $table->string('value_column', 20)->default('base')->after('concept_code');
        });
    }

    public function down(): void
    {
        Schema::table('exogena_account_mappings', function (Blueprint $table) {
            $table->dropColumn('value_column');
        });
    }
};
