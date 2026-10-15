<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué está hecho un combo.
 *
 * El tipo «kit / combo» existía desde el principio y la ficha del producto
 * prometía que «al venderse desglosa componentes», pero no había dónde
 * guardarlos: ni tabla, ni relación, ni pantalla. Era una etiqueta y nada más.
 *
 * Un combo no lleva inventario propio —se arma en el momento de venderlo—, así
 * que al venderlo lo que sale del almacén son sus componentes, cada uno por su
 * cantidad. Por eso esta tabla no es decorativa: es la que decide qué se
 * descuenta y cuánto costó la venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // El combo. Si se borra, sus componentes se van con él: una
            // receta sin plato no significa nada.
            $table->foreignId('kit_product_id')
                ->constrained('products')->cascadeOnDelete();

            // La pieza. `restrict` a proposito: borrar un producto que forma
            // parte de un combo dejaria el combo imposible de vender, y es
            // mejor que el sistema lo impida a que lo descubra el cajero.
            $table->foreignId('component_product_id')
                ->constrained('products')->restrictOnDelete();

            $table->decimal('quantity', 14, 3)->default(1);

            $table->timestamps();

            // Un componente aparece una sola vez por combo: dos filas del
            // mismo producto son una cantidad mal escrita, no dos piezas.
            $table->unique(['kit_product_id', 'component_product_id'], 'product_components_unicos');
            $table->index(['company_id', 'kit_product_id']);
            // Para responder «¿en qué combos entra este producto?» sin
            // recorrer la tabla entera.
            $table->index(['company_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_components');
    }
};
