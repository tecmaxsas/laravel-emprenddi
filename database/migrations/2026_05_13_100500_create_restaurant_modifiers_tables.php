<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modificadores para productos de restaurante:
 *  - ModifierGroup: agrupador ("Punto de cocción", "Extras", "Quitar")
 *  - Modifier: opción individual con delta de precio ("Extra queso +$2000")
 *
 * Un grupo se asigna a N productos (pivot product_restaurant_modifier_group).
 * Al agregar el producto al pedido, el cajero ve los grupos en orden y elige
 * los modifiers según min/max del grupo. El snapshot va al jsonb modifiers
 * del OrderItem y los deltas suman al modifier_total de la línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);              // "Punto de cocción"
            $table->string('description', 200)->nullable();
            $table->unsignedTinyInteger('min_select')->default(0);  // 0 = opcional
            $table->unsignedTinyInteger('max_select')->default(1);  // 1 = radio, >1 = checkbox
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });

        Schema::create('restaurant_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_modifier_group_id')
                ->constrained('restaurant_modifier_groups')->cascadeOnDelete();
            $table->string('name', 80);              // "Extra queso", "Sin cebolla"
            $table->decimal('price_delta', 18, 2)->default(0);  // puede ser 0, +, o negativo
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['restaurant_modifier_group_id', 'active']);
        });

        // Pivot: qué grupos aplican a qué productos.
        // Nombres FK explícitos: el auto-generado pasa 63 chars y Postgres lo trunca.
        Schema::create('product_restaurant_modifier_group', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('restaurant_modifier_group_id');
            $table->unsignedSmallInteger('display_order')->default(0);

            $table->foreign('product_id', 'prmg_product_fk')
                ->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('restaurant_modifier_group_id', 'prmg_group_fk')
                ->references('id')->on('restaurant_modifier_groups')->cascadeOnDelete();

            $table->primary(['product_id', 'restaurant_modifier_group_id'], 'prmg_pivot_pk');
        });

        // Total de deltas de la línea (para no recalcular del jsonb cada vez)
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->decimal('modifier_total', 18, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->dropColumn('modifier_total');
        });
        Schema::dropIfExists('product_restaurant_modifier_group');
        Schema::dropIfExists('restaurant_modifiers');
        Schema::dropIfExists('restaurant_modifier_groups');
    }
};
