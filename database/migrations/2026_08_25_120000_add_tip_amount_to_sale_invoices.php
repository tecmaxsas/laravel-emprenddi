<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propina cobrada al cliente sobre la factura.
 *
 * NO entra en total ni en net_payable: contablemente la propina no es ingreso
 * de la empresa, sigue yendo a su propio asiento contra la cuenta de propina
 * por pagar (RestaurantOrderEngine::recordTipJournalEntry). Este campo existe
 * para que la factura electronica pueda declararla ante DIAN como un cargo
 * (allowance_charges con charge_indicator=true), que es como la exige el
 * proveedor, y para que la representacion grafica cuadre con lo que el cliente
 * realmente pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->decimal('tip_amount', 14, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropColumn('tip_amount');
        });
    }
};
