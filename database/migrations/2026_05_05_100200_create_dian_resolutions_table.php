<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resoluciones DIAN por empresa.
 * Una empresa puede tener múltiples resoluciones (1 por tipo de documento:
 * factura, nota crédito, nota débito, nómina, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // type_document_id según DIAN: 1=FE, 2=NC, 3=ND, 4=Doc Soporte, 5=Nómina, etc.
            $table->unsignedSmallInteger('document_type_id');
            $table->string('document_type_name', 100); // 'Factura Electrónica' etc, para display

            $table->string('prefix', 10)->nullable();
            $table->string('resolution_number', 30)->nullable();
            $table->date('resolution_date')->nullable();
            $table->string('technical_key', 100)->nullable(); // solo FE
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'document_type_id', 'prefix']);
            $table->index(['company_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_resolutions');
    }
};
