<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centros de costo (CC): unidades de imputación contable alternas al PUC
 * para agrupar gastos/ingresos por área, sucursal, proyecto, línea de
 * negocio, etc. Jerárquico — un CC puede tener un padre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();

            $table->string('code', 20);
            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->boolean('active')->default(true);
            $table->boolean('accepts_movements')->default(true);  // false = solo agrupa hijos

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
            $table->index('parent_id');
        });

        // Ahora sí agregamos la FK al campo cost_center_id que ya estaba
        // en journal_entry_lines (creado como nullable sin constraint).
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreign('cost_center_id')
                ->references('id')->on('cost_centers')
                ->nullOnDelete();
            $table->index('cost_center_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropIndex(['cost_center_id']);
        });

        Schema::dropIfExists('cost_centers');
    }
};
