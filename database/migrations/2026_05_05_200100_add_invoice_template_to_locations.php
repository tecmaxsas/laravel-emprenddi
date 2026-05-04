<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación de template de impresión a cada sede.
 * Nullable: una sede sin template asignado usa el template default de la empresa
 * (el primero con is_default=true), o el primero activo si no hay default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('invoice_template_id')
                ->nullable()
                ->after('manager_user_id')
                ->constrained('invoice_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_template_id');
        });
    }
};
