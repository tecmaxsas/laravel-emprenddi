<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El depósito de la reserva, conectado a la plata de verdad.
 *
 * `deposit_amount` existía desde el principio y no hacía nada: se escribía, se
 * mostraba y nadie lo leía. No se cruzaba con ningún anticipo, no se
 * descontaba al facturar y no pasaba nada con él si el cliente no llegaba. Un
 * hotel que ve el campo asume que sí —es lo razonable— y cuando descubre que
 * no, ya cobró mal.
 *
 * Ahora son dos cosas distintas, que es lo que siempre fueron:
 *
 *   deposit_amount      lo PACTADO. «Para apartar son $200.000.»
 *   deposit_advance_id  lo RECIBIDO: el anticipo de cliente con el que entró
 *                       esa plata, con su fecha, su forma de pago y su turno
 *                       de caja.
 *
 * El anticipo no es nuevo: es la misma figura que ya usa el folio y que
 * SaleInvoiceEngine cruza solo contra la factura siguiente del cliente. Lo que
 * faltaba era el hilo entre la reserva y él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->foreignId('deposit_advance_id')->nullable()->after('deposit_amount')
                ->constrained('customer_advances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_advance_id');
        });
    }
};
