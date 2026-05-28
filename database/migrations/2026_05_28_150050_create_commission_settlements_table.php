<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->cascadeOnDelete();

            // Periodo liquidado
            $table->date('period_start');
            $table->date('period_end');

            // Total de comisiones liquidadas (suma de las entries incluidas)
            $table->decimal('total_amount', 16, 2);
            $table->integer('entries_count')->default(0);

            // draft  → calculada, sin contabilizar/pagar
            // paid   → contabilizada (asiento generado) y marcada como pagada
            $table->string('status', 20)->default('draft');

            // Asiento contable: DR Gasto comisiones / CR Comisiones por pagar
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('settled_at')->nullable();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'seller_user_id', 'status']);
            $table->index(['company_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settlements');
    }
};
