<?php

use App\Models\OrderTaking\Order;
use App\Models\Scopes\CompanyScope;
use App\Services\OrderTaking\OrderEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el IVA guardado en las lineas de los pedidos.
 *
 * order_taking_order_items.tax_amount vive al lado de subtotal y total, que
 * son valores DE LA LINEA, pero se venia guardando el IVA UNITARIO: el codigo
 * calculaba el de la linea y luego grababa el otro. Como recomputeTotals suma
 * esa columna para el IVA del pedido, cualquier pedido con cantidad mayor a 1
 * quedo con el IVA subestimado y con total != subtotal + IVA.
 *
 * Se multiplica por la cantidad pedida, que es justo lo que faltaba, y se
 * recalculan las cabeceras. Las lineas de cantidad 1 no se mueven porque en
 * ellas el unitario y el de linea son el mismo numero.
 *
 * No se puede deshacer: dividir de vuelta daria el valor equivocado en las
 * lineas creadas despues de la correccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_taking_order_items')
            ->where('quantity_ordered', '>', 1)
            ->update([
                'tax_amount' => DB::raw('tax_amount * quantity_ordered'),
            ]);

        $engine = app(OrderEngine::class);

        Order::withoutGlobalScope(CompanyScope::class)
            ->chunkById(200, function ($orders) use ($engine) {
                foreach ($orders as $order) {
                    $engine->recomputeTotals($order);
                }
            });
    }

    public function down(): void
    {
        // Sin vuelta atras: ver la nota de arriba.
    }
};
