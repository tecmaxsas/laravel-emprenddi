<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuanto del saldo de apertura ya se le abono al cliente.
 *
 * EL PROBLEMA. El saldo de apertura —lo que el cliente ya debia cuando la
 * empresa empezo a usar Emprenddi— entraba pero no salia: los anticipos se
 * aplicaban SOLO a las facturas del sistema, nunca a esa deuda vieja. Un
 * cliente podia abonar durante meses y el renglon mas antiguo de su estado de
 * cuenta seguia intacto, que es justo el que lleva mas tiempo sin cobrarse.
 *
 * POR QUE UNA COLUMNA APARTE Y NO RESTARLE A `opening_balance`. Porque el
 * valor original es un dato: es lo que el cliente debia el dia de la
 * migracion, y el estado de cuenta tiene que poder mostrarlo como el
 * movimiento que abre la hoja. Restarselo lo borraria, y entonces nadie podria
 * explicar de donde salio el saldo.
 *
 * Es el mismo criterio de `sale_invoices.credited_amount` y de `paid_amount`:
 * el documento conserva su valor y lo aplicado se acumula al lado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->decimal('opening_balance_applied', 16, 2)
                ->default(0)
                ->after('opening_balance_date');
        });
    }

    public function down(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->dropColumn('opening_balance_applied');
        });
    }
};
