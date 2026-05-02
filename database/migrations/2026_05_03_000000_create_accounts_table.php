<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->enum('type', [
                'activo',
                'pasivo',
                'patrimonio',
                'ingreso',
                'gasto',
                'costo_venta',
                'costo_produccion',
                'orden_deudora',
                'orden_acreedora',
            ]);
            $table->enum('nature', ['debit', 'credit']);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedTinyInteger('level');
            $table->boolean('accepts_movements')->default(false);
            $table->boolean('requires_third_party')->default(false);
            $table->boolean('requires_cost_center')->default(false);
            $table->boolean('active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'accepts_movements', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
