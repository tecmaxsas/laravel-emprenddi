<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones de venta: que rebajen la cartera, y que existan para el POS.
 *
 * DOS HUECOS QUE SE CIERRAN AQUI.
 *
 * 1. UNA NOTA CREDITO NO REBAJABA LO QUE EL CLIENTE DEBIA.
 *
 * El asiento acreditaba la CxC —los libros quedaban bien— pero el saldo de la
 * factura es `net_payable - paid_amount` y nada mas lo tocaba. Resultado: el
 * cliente devolvia la mercancia y seguia debiendo lo mismo en el estado de
 * cuenta, en la cartera por edades y en el cupo de credito. Los libros y la
 * cartera decian cosas distintas, y lo que se cobra es la cartera.
 *
 * `credited_amount` es el acumulado de notas credito contabilizadas contra esa
 * factura. Va denormalizado en la factura, igual que `paid_amount` y por la
 * misma razon: la cartera y el reporte de edades recorren facturas en bloque y
 * no pueden sumar notas por cada fila.
 *
 * 2. UNA FACTURA POS NO TENIA COMO DEVOLVERSE.
 *
 * La nota credito electronica existe para la factura electronica: lleva
 * resolucion de la DIAN, consecutivo propio y se transmite. El tiquete POS no
 * se transmite, asi que una devolucion sobre el no es una nota credito — es un
 * documento interno.
 *
 * En vez de un modelo paralelo con su propio motor —inventario, COGS, asiento,
 * todo duplicado y listo para divergir— el documento es el mismo con un `kind`
 * que decide si viaja a la DIAN. Lo que de verdad cambia no es el tipo de
 * documento sino COMO se le devuelve la plata al cliente, y eso es
 * `refund_method`: rebajar el saldo de la factura (venta a credito) o sacar
 * efectivo del cajon (venta de contado, que es el caso POS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->decimal('credited_amount', 16, 2)->default(0)->after('paid_amount');
        });

        Schema::table('credit_debit_notes', function (Blueprint $table) {
            // 'electronic' | 'pos'. Null se trata como electronica, igual que
            // en sale_invoices, para no reescribir las notas que ya existen.
            $table->string('kind', 20)->nullable()->after('type');

            // Como se le devuelve la plata: 'balance' rebaja el saldo de la
            // factura, 'cash' (o cualquier metodo de pago) la saca del cajon.
            $table->string('refund_method', 40)->nullable()->after('affects_inventory');
            $table->decimal('refund_amount', 16, 2)->default(0)->after('refund_method');
            $table->foreignId('cash_register_session_id')->nullable()->after('refund_amount');

            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_debit_notes', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind']);
            $table->dropColumn(['kind', 'refund_method', 'refund_amount', 'cash_register_session_id']);
        });

        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });
    }
};
