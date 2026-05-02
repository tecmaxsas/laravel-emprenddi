<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->boolean('active')->default(true)->after('is_super_admin');
            $table->timestamp('last_login_at')->nullable()->after('active');

            $table->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'is_super_admin', 'active', 'last_login_at']);
        });
    }
};
