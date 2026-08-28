<?php

use App\Models\OrderTaking\Order;
use App\Models\Scopes\CompanyScope;
use App\Services\OrderTaking\OrderEngine;
use Illuminate\Database\Migrations\Migration;

/**
 * Pone al dia el estado de despacho de los pedidos que ya existen.
 *
 * refreshDeliveryStatus() recargaba las lineas con loadMissing(), que devuelve
 * la coleccion ya cargada en memoria: como se llama justo despues de mover las
 * cantidades, calculaba el estado con los datos de ANTES del despacho. El
 * resultado es que pedidos despachados por completo se quedaron marcados como
 * pendientes, aunque sus lineas si tienen la cantidad entregada correcta.
 *
 * El error ya esta corregido en el motor; esto arregla lo que quedo mal
 * guardado. Se recalcula desde las lineas, que son la fuente de verdad, asi
 * que no se inventa nada.
 *
 * No toca pedidos anulados: su estado es una decision, no un calculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $engine = app(OrderEngine::class);

        // Solo se quita el scope de empresa: los pedidos borrados siguen
        // fuera, que su estado ya no le importa a nadie.
        Order::withoutGlobalScope(CompanyScope::class)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->chunkById(200, function ($orders) use ($engine) {
                foreach ($orders as $order) {
                    $engine->refreshDeliveryStatus($order);
                }
            });
    }

    public function down(): void
    {
        // Recalcular desde las lineas no se deshace: el estado anterior era el
        // equivocado y no hay a donde volver.
    }
};
