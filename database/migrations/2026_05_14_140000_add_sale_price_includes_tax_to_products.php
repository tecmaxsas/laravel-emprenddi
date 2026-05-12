<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indica si el default_sale_price ya incluye el IVA/INC o si es el
 * precio base (antes de impuestos). Default false para no cambiar el
 * comportamiento de los productos existentes.
 *
 * Cuando true:
 *  - precio mostrado al cliente = default_sale_price (final, con IVA)
 *  - se desnormaliza para calcular subtotal: price / (1 + rate/100)
 *  - asi unit_price guardado en order_items / invoice_lines queda en
 *    base price (consistente con el resto del modelo contable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sale_price_includes_tax')->default(false)->after('default_sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sale_price_includes_tax');
        });
    }
};
