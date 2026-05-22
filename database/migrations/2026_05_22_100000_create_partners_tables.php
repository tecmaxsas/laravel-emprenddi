<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de socios — registro legal de socios/accionistas de la empresa
 * y sus movimientos de capital (aportes, capitalizaciones, cesiones,
 * retiros). El saldo de cuotas/acciones y el % de participación se
 * derivan de la suma de los movimientos; no contabiliza por sí mismo,
 * es un libro de registro que el contador concilia con la cuenta 31xx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('person_type', 20)->default('natural');
            $table->string('document_type', 20)->default('cc');
            $table->string('document_number', 30);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('partner_type', 20)->default('socio');
            $table->date('joined_at');
            $table->date('withdrawn_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::create('partner_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('movement_type', 30);
            $table->decimal('quotas', 18, 4)->default(0);
            $table->decimal('unit_value', 18, 4)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_movements');
        Schema::dropIfExists('partners');
    }
};
