<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('nit', 20)->unique();
            $table->string('dv', 1)->nullable();
            $table->enum('regime_type', ['simplificado', 'comun', 'gran_contribuyente', 'no_responsable_iva'])
                ->default('comun');
            $table->enum('accounting_method', ['niif_pymes', 'niif_full'])->default('niif_pymes');
            $table->enum('inventory_method', ['fifo', 'lifo', 'weighted_average'])->default('weighted_average');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('department')->nullable();
            $table->string('country', 2)->default('CO');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('currency', 3)->default('COP');
            $table->string('timezone', 64)->default('America/Bogota');
            $table->json('active_modules')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
