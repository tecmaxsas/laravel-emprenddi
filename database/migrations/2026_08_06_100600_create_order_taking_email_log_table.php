<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_taking_email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained('order_taking_orders')
                ->cascadeOnDelete();

            $table->timestamp('sent_at');
            $table->string('to_address', 200);
            // cc como texto (comma-separated) para no crear tabla adicional
            $table->text('cc_addresses')->nullable();
            $table->string('subject', 250);
            $table->foreignId('sent_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('sent'); // sent | failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_taking_email_log');
    }
};
