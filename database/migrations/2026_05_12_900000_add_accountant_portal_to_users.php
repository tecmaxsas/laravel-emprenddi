<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal Contador: marca un user como contador externo (multi-empresa).
 * Su company_id queda NULL — opera sobre las empresas listadas en
 * accountant_companies seleccionando una activa por sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_accountant_portal')->default(false)->after('is_super_admin');
            $table->index(['is_accountant_portal', 'active'], 'users_accountant_portal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_accountant_portal_idx');
            $table->dropColumn('is_accountant_portal');
        });
    }
};
