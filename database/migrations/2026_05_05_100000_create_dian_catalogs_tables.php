<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos DIAN — datos de referencia compartidos entre todas las empresas.
 * Read-only desde la app: los seeders los pueblan, no se editan por UI.
 *
 * Naming convention: prefix `dian_` para distinguir de tablas de negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('dian_municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dian_department_id')->constrained()->restrictOnDelete();
            $table->string('code', 10)->unique();
            $table->string('name', 150);
            $table->unsignedBigInteger('codefacturador')->nullable();
            $table->timestamps();

            $table->index(['dian_department_id', 'name']);
            $table->index('codefacturador');
        });

        Schema::create('dian_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('dian_organization_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 50);
            $table->timestamps();
        });

        Schema::create('dian_regime_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('dian_tax_liabilities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 15)->unique();
            $table->string('name', 200);
            $table->timestamps();
        });

        Schema::create('dian_payment_forms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 50);
            $table->timestamps();
        });

        Schema::create('dian_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 200);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_payment_methods');
        Schema::dropIfExists('dian_payment_forms');
        Schema::dropIfExists('dian_tax_liabilities');
        Schema::dropIfExists('dian_regime_types');
        Schema::dropIfExists('dian_organization_types');
        Schema::dropIfExists('dian_document_types');
        Schema::dropIfExists('dian_municipalities');
        Schema::dropIfExists('dian_departments');
    }
};
