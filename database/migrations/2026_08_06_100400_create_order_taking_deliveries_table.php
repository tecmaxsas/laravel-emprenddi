<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_taking_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained('order_taking_orders')
                ->cascadeOnDelete();

            $table->string('delivery_number', 30)->nullable();
            $table->date('delivery_date');
            $table->foreignId('delivered_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'order_id']);
            $table->index(['company_id', 'delivery_date']);
        });

        Schema::create('order_taking_delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')
                ->constrained('order_taking_deliveries')
                ->cascadeOnDelete();
            $table->foreignId('order_item_id')
                ->constrained('order_taking_order_items')
                ->cascadeOnDelete();
            $table->decimal('quantity_delivered', 12, 3);
            $table->timestamps();

            $table->index(['company_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_taking_delivery_items');
        Schema::dropIfExists('order_taking_deliveries');
    }
};
