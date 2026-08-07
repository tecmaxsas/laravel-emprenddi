<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_taking_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Numeracion interna por (company_id, prefix, number).
            $table->string('prefix', 10)->default('PED');
            $table->integer('number');

            $table->foreignId('third_party_id')->constrained('third_parties');
            $table->foreignId('price_list_id')->nullable()
                ->constrained('order_taking_price_lists')->nullOnDelete();
            $table->foreignId('location_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            $table->foreignId('seller_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->date('order_date');
            $table->date('delivery_date_expected')->nullable();

            // draft: en edicion | confirmed: enviado a bodega | partial_delivered
            // fully_delivered | cancelled
            $table->string('status', 24)->default('draft');

            // pending | partial | delivered (auto-calculado desde items)
            $table->string('delivery_status', 12)->default('pending');

            // pendiente | parcial | pagado (auto-calculado desde payments)
            $table->string('payment_status', 12)->default('pendiente');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'delivery_status']);
            $table->index(['company_id', 'payment_status']);
            $table->index(['company_id', 'third_party_id']);
            $table->index(['company_id', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_taking_orders');
    }
};
