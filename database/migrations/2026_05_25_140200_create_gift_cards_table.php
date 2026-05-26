<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Codigo unico — el que el cliente presenta para redimir.
            // Unique por empresa para evitar colisiones entre empresas.
            $table->string('code', 30);

            // Saldos
            $table->decimal('initial_balance', 14, 2);
            $table->decimal('current_balance', 14, 2);
            $table->string('currency', 3)->default('COP');

            // Estado
            // active: aun tiene saldo y no esta expirada
            // fully_redeemed: saldo en 0
            // expired: paso la fecha de expiracion
            // cancelled: anulada manualmente por admin
            $table->string('status', 20)->default('active');

            // Emision
            $table->dateTime('issued_at');
            $table->foreignId('issued_by_user_id')->constrained('users')->cascadeOnDelete();
            // Referencia a la venta que origino la gift card (si se vendio via POS)
            $table->foreignId('issued_via_sale_invoice_id')->nullable()->constrained('sale_invoices')->nullOnDelete();

            // Expiracion opcional
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_redeemed_at')->nullable();

            // Quien la recibe / quien la regala (opcional)
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient_email', 150)->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->string('sender_name', 150)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Indices
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
