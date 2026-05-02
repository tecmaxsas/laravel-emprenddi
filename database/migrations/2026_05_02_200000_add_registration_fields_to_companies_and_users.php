<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('document_type', ['cc', 'ce', 'ti', 'nit', 'pasaporte', 'rut'])
                ->default('nit')
                ->after('legal_name');

            $table->enum('organization_type', ['natural', 'juridica'])
                ->default('juridica')
                ->after('document_type');

            $table->string('phone_country_code', 5)->default('+57')->after('phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('last_name')->nullable()->after('name');
            $table->timestamp('accepted_terms_at')->nullable()->after('email_verified_at');
            $table->boolean('marketing_opt_in')->default(false)->after('accepted_terms_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_name', 'accepted_terms_at', 'marketing_opt_in']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['document_type', 'organization_type', 'phone_country_code']);
        });
    }
};
