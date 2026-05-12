<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot M:N entre contadores (users con is_accountant_portal=true) y las
 * empresas cuya contabilidad llevan. El superadmin gestiona la vinculación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accountant_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->boolean('active')->default(true);

            $table->timestamp('granted_at')->useCurrent();
            $table->foreignId('granted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
            $table->index(['user_id', 'active']);
            $table->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accountant_companies');
    }
};
