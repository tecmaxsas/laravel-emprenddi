<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_product_id')->nullable()->after('company_id')
                ->constrained('products')->nullOnDelete();
            $table->json('attributes')->nullable()->after('brand');

            $table->index(['company_id', 'parent_product_id']);
        });

        // Postgres: actualizar el CHECK constraint del enum 'type' para incluir 'variable'
        DB::statement("ALTER TABLE products DROP CONSTRAINT IF EXISTS products_type_check");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('good', 'service', 'kit', 'consumable', 'variable'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE products DROP CONSTRAINT IF EXISTS products_type_check");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('good', 'service', 'kit', 'consumable'))");

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_product_id']);
            $table->dropColumn(['parent_product_id', 'attributes']);
        });
    }
};
