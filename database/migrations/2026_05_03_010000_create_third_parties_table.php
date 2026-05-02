<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('third_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->enum('person_type', ['natural', 'juridica'])->default('juridica');
            $table->enum('document_type', ['cc', 'ce', 'ti', 'nit', 'pasaporte', 'rut', 'nuip', 'die'])
                ->default('nit');
            $table->string('document_number', 30);
            $table->string('dv', 1)->nullable();

            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('second_last_name', 100)->nullable();
            $table->string('trade_name', 200)->nullable();

            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('country', 2)->default('CO');
            $table->string('postal_code', 20)->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('website', 200)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();

            $table->enum('regime_type', ['comun', 'no_responsable_iva', 'gran_contribuyente', 'simplificado'])
                ->default('comun');
            $table->boolean('is_self_withholder')->default(false);
            $table->boolean('is_iva_withholder')->default(false);
            $table->boolean('is_ica_withholder')->default(false);
            $table->json('tax_responsibilities')->nullable();

            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_employee')->default(false);
            $table->boolean('is_other')->default(false);

            $table->foreignId('default_receivable_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_payable_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_seller_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);

            $table->date('birth_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'document_number']);
            $table->index(['company_id', 'is_customer', 'active']);
            $table->index(['company_id', 'is_supplier', 'active']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_parties');
    }
};
