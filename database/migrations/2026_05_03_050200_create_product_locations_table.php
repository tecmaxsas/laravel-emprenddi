<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->decimal('min_stock', 15, 4)->nullable();
            $table->decimal('max_stock', 15, 4)->nullable();
            $table->decimal('reorder_point', 15, 4)->nullable();
            $table->decimal('override_sale_price', 15, 2)->nullable();
            $table->decimal('override_purchase_price', 15, 2)->nullable();
            $table->string('shelf_location', 50)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'location_id']);
            $table->index(['location_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_locations');
    }
};
