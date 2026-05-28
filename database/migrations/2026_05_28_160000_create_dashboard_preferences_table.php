<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Secciones que el usuario eligio OCULTAR (array de keys, ej. ['payroll','restaurant'])
            $table->json('hidden_sections')->nullable();
            // Orden personalizado de las secciones (array de keys ordenadas)
            $table->json('section_order')->nullable();

            $table->timestamps();

            // Una preferencia por usuario (las pref son personales, no por empresa,
            // pero guardamos company_id para scope/limpieza).
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_preferences');
    }
};
