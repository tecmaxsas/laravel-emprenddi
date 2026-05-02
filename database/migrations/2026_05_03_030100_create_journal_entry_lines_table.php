<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable();
            $table->string('description', 250)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['journal_entry_id', 'line_number']);
            $table->index('account_id');
            $table->index('third_party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
