<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ata el abono al despacho que lo origina.
 *
 * Antes el abono colgaba del pedido, asi que no habia forma de saber que
 * entrega estaba pagando el cliente. Ahora primero se despacha y el abono se
 * registra dentro de ese despacho.
 *
 * Queda nullable a proposito: los abonos que ya existen no se pueden repartir
 * entre despachos sin inventar datos, asi que se quedan colgando del pedido y
 * se muestran aparte como historicos. Los nuevos siempre traen delivery_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_taking_payments', function (Blueprint $table) {
            $table->foreignId('delivery_id')
                ->nullable()
                ->after('order_id')
                ->constrained('order_taking_deliveries')
                ->nullOnDelete();

            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_taking_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_id');
        });
    }
};
