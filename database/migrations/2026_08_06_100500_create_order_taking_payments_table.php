<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_taking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained('order_taking_orders')
                ->cascadeOnDelete();

            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30)->default('cash');
            $table->foreignId('account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'order_id']);
            $table->index(['company_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_taking_payments');
    }
};
