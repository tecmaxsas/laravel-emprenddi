<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto entregó el cliente y cuánto se le devolvió.
 *
 * El cajero hacía la resta de cabeza. Con un total de $47.300 y un billete de
 * $50.000 no falla nadie; con tres productos, un descuento y $100.000 encima
 * del mostrador, sí — y el faltante aparece al arquear, cuando ya nadie sabe
 * en cuál venta fue.
 *
 * Van en el pago y no en la factura porque son del **efectivo entregado**, no
 * de la venta: una factura pagada mitad en efectivo y mitad por transferencia
 * tiene vuelto solo en la parte de efectivo.
 *
 * Las dos columnas son opcionales a propósito. Un cobro por transferencia o
 * con tarjeta no tiene ni entrega ni vuelto, y guardar ceros ahí haría creer
 * que sí los hubo.
 *
 * `amount` no cambia: sigue siendo lo que la venta cobró. El vuelto no es una
 * salida de caja, es la parte del billete que nunca fue del negocio —por eso
 * `cash_received - change_given` tiene que dar `amount`—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('cash_received', 14, 2)->nullable()->after('amount');
            $table->decimal('change_given', 14, 2)->nullable()->after('cash_received');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['cash_received', 'change_given']);
        });
    }
};
