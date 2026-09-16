<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué sucursal del cliente va el pedido y, después, la factura.
 *
 * Nulo es lo normal: la inmensa mayoría de los clientes no tiene sucursales y
 * sus documentos siguen exactamente igual.
 *
 * **La factura se sigue emitiendo al NIT del tercero.** Esta columna no cambia a
 * quién se factura —eso lo valida la DIAN y no es negociable—: dice a dónde se
 * entrega, y permite después abrir la cartera por sucursal para saber de dónde
 * viene cada saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->foreignId('third_party_branch_id')->nullable()->after('third_party_id')
                ->constrained('third_party_branches')->nullOnDelete();
        });

        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->foreignId('third_party_branch_id')->nullable()->after('third_party_id')
                ->constrained('third_party_branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('third_party_branch_id');
        });

        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('third_party_branch_id');
        });
    }
};
