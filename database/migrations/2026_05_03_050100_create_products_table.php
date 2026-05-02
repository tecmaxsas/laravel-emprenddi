<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 50);
            $table->string('barcode', 50)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();

            $table->foreignId('category_id')->nullable()
                ->constrained('categories')->nullOnDelete();

            $table->enum('type', ['good', 'service', 'kit', 'consumable'])->default('good');
            $table->string('unit_of_measure', 30)->default('unit');

            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('is_sellable')->default(true);

            $table->decimal('default_purchase_price', 15, 2)->default(0);
            $table->decimal('default_sale_price', 15, 2)->default(0);
            $table->decimal('min_sale_price', 15, 2)->nullable();

            $table->foreignId('default_purchase_tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();
            $table->foreignId('default_sale_tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();

            $table->foreignId('inventory_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('sale_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('cost_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();

            $table->string('image_path', 255)->nullable();
            $table->string('brand', 100)->nullable();
            $table->boolean('active')->default(true);

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'barcode']);
            $table->index(['company_id', 'category_id', 'active']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
