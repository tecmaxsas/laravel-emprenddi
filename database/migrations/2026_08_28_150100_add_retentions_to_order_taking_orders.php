<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones en la cabecera del pedido, con el mismo criterio que ya usan las
 * facturas de venta:
 *
 *   retention_total = suma de las lineas de order_taking_order_retentions
 *   net_payable     = total - retention_total = lo que el cliente realmente paga
 *
 * El saldo se calcula contra net_payable y no contra total, porque la
 * retencion no es un saldo por cobrar: es un anticipo de impuesto que el
 * cliente le consigna a la DIAN en nuestro nombre.
 *
 * Los pedidos que ya existen quedan con retention_total = 0 y net_payable
 * igual a su total, asi que su saldo no se mueve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->decimal('retention_total', 18, 2)->default(0)->after('tax_total');
            $table->decimal('net_payable', 18, 2)->default(0)->after('total');
        });

        // Sin esto los pedidos existentes quedarian con net_payable en 0 y el
        // sistema los daria por pagados.
        DB::statement('UPDATE order_taking_orders SET net_payable = total');
    }

    public function down(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->dropColumn(['retention_total', 'net_payable']);
        });
    }
};
