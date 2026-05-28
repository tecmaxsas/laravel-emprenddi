<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_user_id')->constrained('users')->cascadeOnDelete();

            // Alcance de la regla:
            //  all      → % base del vendedor (aplica a todo lo que venda)
            //  category → override para una categoria especifica
            //  product  → override para un producto especifico
            $table->string('scope', 20)->default('all'); // all, category, product
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Porcentaje de comision (0-100)
            $table->decimal('rate', 8, 4);

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Un vendedor no deberia tener dos reglas 'all', ni dos para la
            // misma categoria/producto. Unique compuesto lo garantiza.
            $table->unique(['company_id', 'seller_user_id', 'scope', 'category_id', 'product_id'], 'commission_rules_unique');
            $table->index(['company_id', 'seller_user_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
