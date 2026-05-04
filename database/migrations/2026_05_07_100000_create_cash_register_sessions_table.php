<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de caja del POS — turnos del cajero.
 *
 * Una sesión por (cashier, sede). El cajero abre con un valor inicial
 * (efectivo en caja al empezar el turno) y la cierra declarando lo que
 * cuenta físicamente. El sistema calcula el saldo esperado sumando los
 * pagos en efectivo de las facturas de la sesión + opening_amount.
 *
 *   closing_difference = closing_counted - closing_expected
 *     positivo = sobrante (cajero declaró más de lo esperado)
 *     negativo = faltante
 *
 * Cuando settings.pos.blind_cash_close=true, el cajero NO ve el expected
 * al cerrar — solo digita lo contado y el sistema calcula la diferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', ['open', 'closed'])->default('open');

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->decimal('opening_amount', 18, 2)->default(0);
            $table->decimal('closing_expected', 18, 2)->nullable();
            $table->decimal('closing_counted', 18, 2)->nullable();
            $table->decimal('closing_difference', 18, 2)->nullable();

            // Snapshot al cerrar — útil para reportes históricos sin recalcular
            $table->decimal('total_sales', 18, 2)->default(0);
            $table->unsignedInteger('invoice_count')->default(0);
            $table->json('payment_breakdown')->nullable(); // {method => amount}

            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status', 'cashier_user_id']);
            $table->index(['company_id', 'location_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};
