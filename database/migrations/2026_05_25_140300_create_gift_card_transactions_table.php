<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();

            // Tipo de movimiento
            // issue: carga inicial (al emitirse)
            // redeem: redencion en POS (negativo)
            // refund: devolucion (positivo, vuelve a cargar)
            // expire: ajuste por expiracion (negativo, lleva a 0)
            // adjust: ajuste manual admin (positivo o negativo)
            // cancel: anulacion (negativo, lleva a 0)
            $table->string('type', 20);

            // Positivo = carga, negativo = consume
            $table->decimal('amount', 14, 2);

            // Saldo despues de la transaccion (snapshot)
            $table->decimal('balance_after', 14, 2);

            // Referencias opcionales
            $table->foreignId('sale_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Indices para historico por tarjeta
            $table->index(['gift_card_id', 'created_at']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_transactions');
    }
};
