<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración DIAN por empresa.
 * Una empresa = una fila aquí. Guarda token JWT, software, certificado,
 * y los IDs de catálogos DIAN seleccionados (que se mapean a los enums
 * locales de companies pero son IDs específicos DIAN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_company_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // Token JWT que devuelve la API DIAN al registrar la empresa
            $table->text('api_token')->nullable();
            $table->string('api_url', 200)->default('https://apidian.emprenddi.com');

            // Ambiente: 1=Producción, 2=Pruebas (default test)
            $table->unsignedTinyInteger('environment')->default(2);

            // FKs a los catálogos DIAN
            $table->foreignId('dian_document_type_id')->nullable()
                ->constrained('dian_document_types')->nullOnDelete();
            $table->foreignId('dian_organization_type_id')->nullable()
                ->constrained('dian_organization_types')->nullOnDelete();
            $table->foreignId('dian_regime_type_id')->nullable()
                ->constrained('dian_regime_types')->nullOnDelete();
            $table->foreignId('dian_tax_liability_id')->nullable()
                ->constrained('dian_tax_liabilities')->nullOnDelete();
            $table->foreignId('dian_municipality_id')->nullable()
                ->constrained('dian_municipalities')->nullOnDelete();

            $table->string('merchant_registration', 50)->nullable();

            // Software
            $table->string('software_id', 100)->nullable();
            $table->text('software_pin')->nullable(); // encriptado

            // Certificado
            $table->string('certificate_filename', 200)->nullable();
            $table->text('certificate_password')->nullable(); // encriptado

            // Estado de cada paso (para mostrar progreso en el wizard)
            $table->boolean('company_registered')->default(false);
            $table->boolean('software_configured')->default(false);
            $table->boolean('certificate_uploaded')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_company_configs');
    }
};
