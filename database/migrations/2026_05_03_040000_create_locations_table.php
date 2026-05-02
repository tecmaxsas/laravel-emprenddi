<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 30);
            $table->string('name', 150);
            $table->enum('type', ['store', 'warehouse', 'mixed', 'virtual'])->default('mixed');
            $table->boolean('is_main')->default(false);

            $table->foreignId('manager_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('country', 2)->default('CO');
            $table->string('postal_code', 20)->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();

            $table->string('currency', 3)->default('COP');
            $table->string('timezone', 64)->default('America/Bogota');

            $table->json('settings')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
