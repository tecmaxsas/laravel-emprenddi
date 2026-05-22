<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura manual de los formatos de información exógena que no se derivan
 * del libro contable (1004 descuentos tributarios, 1011 declaraciones
 * tributarias). El contador digita los valores con base en la resolución
 * y la declaración de renta del año.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exogena_manual_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('format_code', 4);
            $table->string('concept_code', 20);
            $table->string('concept_name', 200);
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'format_code', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exogena_manual_entries');
    }
};
