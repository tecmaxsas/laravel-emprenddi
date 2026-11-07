<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguir un ABONO de un ANTICIPO.
 *
 * Son dos cosas distintas y el sistema las trataba igual:
 *
 *  - Un ABONO es plata contra una deuda que YA existe. Se aplica de una, y
 *    por eso el cliente no deberia terminar viendo «saldo a favor» por ese
 *    dinero: lo que hizo fue bajar lo que debe.
 *
 *  - Un ANTICIPO es plata por una compra que TODAVIA no existe. Esa si queda
 *    a favor, a proposito, hasta que haya una venta que la consuma.
 *
 * Mezclarlas produjo la queja: el estado de cuenta mostraba «$950.000 a favor»
 * y «$730.000 adeudado» al mismo tiempo, y el cliente leia que tenia casi un
 * millon guardado aparte de su deuda. No lo tenia: ese dinero ya habia bajado
 * la deuda.
 *
 * Null se trata como ABONO: es lo que hacian todos los registros anteriores
 * —se aplicaban de inmediato— asi que reescribirlos seria cambiarles el
 * significado a posteriori.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_advances', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('customer_advances', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
