<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para items compuestos (mitad y mitad, futuros combos).
 *
 * composite_items: jsonb array de partes que conforman la línea:
 *   [
 *     {product_id, name, price, ratio},
 *     {product_id, name, price, ratio},
 *   ]
 *
 * Para una mitad y mitad: 2 partes con ratio 0.5 cada una. El unit_price
 * del item es max(prices) — regla típica colombiana. El producto_id del
 * item apunta al más caro de los dos para atribución en reportes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->jsonb('composite_items')->nullable()->after('modifiers');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->dropColumn('composite_items');
        });
    }
};
