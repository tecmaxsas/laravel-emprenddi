<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('prefix', 10)->default('AS');
            $table->unsignedBigInteger('number');
            $table->date('date');
            $table->enum('type', [
                'manual',
                'opening',
                'closing',
                'adjustment',
                'sale',
                'purchase',
                'receipt',
                'payment',
                'payroll',
                'depreciation',
                'reversal',
            ])->default('manual');
            $table->string('reference', 100)->nullable();
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();
            $table->text('description')->nullable();

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('reversed_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'number']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status', 'date']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
