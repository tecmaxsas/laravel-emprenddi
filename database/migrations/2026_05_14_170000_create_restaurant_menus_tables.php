<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carta pública del restaurante. Cliente accede via QR a /menu/{slug}.
 *
 * Estructura:
 *  - restaurant_menus: contenedor con tema, logo, slug publico (1 por empresa por ahora).
 *  - restaurant_menu_sections: agrupacion ("Entradas", "Platos fuertes", "Bebidas").
 *  - restaurant_menu_items: cada plato/bebida con nombre, descripcion, precio, imagen.
 *
 * theme jsonb (en menus): colores, font, layout (grid|list), tamanos, opciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 60)->unique();
            $table->string('subtitle', 200)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('header_image_path', 255)->nullable();
            $table->jsonb('theme')->nullable();
            $table->boolean('show_prices')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });

        Schema::create('restaurant_menu_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_menu_id')
                ->constrained('restaurant_menus')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('restaurant_menu_id');
        });

        Schema::create('restaurant_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_menu_section_id')
                ->constrained('restaurant_menu_sections')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->nullOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->decimal('price', 18, 2)->default(0);
            $table->string('image_path', 255)->nullable();
            $table->jsonb('tags')->nullable();  // ['vegan', 'gluten_free', 'spicy', 'new']
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('restaurant_menu_section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_menu_items');
        Schema::dropIfExists('restaurant_menu_sections');
        Schema::dropIfExists('restaurant_menus');
    }
};
