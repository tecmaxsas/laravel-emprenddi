<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();

            // Donde se uso
            $table->foreignId('sale_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_third_party_id')->nullable()->constrained('third_parties')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Monto del descuento aplicado en esta venta
            $table->decimal('discount_applied', 14, 2);

            $table->dateTime('applied_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indices para reportes y para validar max_uses_per_customer
            $table->index(['company_id', 'promotion_id']);
            $table->index(['promotion_id', 'customer_third_party_id']);
            $table->index(['company_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_usages');
    }
};
