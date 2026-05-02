<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 30);
            $table->string('name', 150);
            $table->enum('type', [
                'vat',                  // IVA
                'consumption_tax',      // INC
                'income_withholding',   // Retención en la fuente (renta)
                'vat_withholding',      // ReteIVA
                'ica_withholding',      // ReteICA
                'other',
            ]);
            $table->decimal('rate', 7, 4);

            $table->foreignId('sale_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('purchase_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();

            $table->decimal('minimum_base', 15, 2)->default(0);
            $table->enum('applies_to', ['sale', 'purchase', 'both'])->default('both');

            $table->boolean('is_default_for_sale')->default(false);
            $table->boolean('is_default_for_purchase')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->text('description')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'applies_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
