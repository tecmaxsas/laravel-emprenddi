<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerrar un pedido que todavía tiene líneas sin despachar.
 *
 * Pasa todo el tiempo: de un pedido de cuarenta referencias quedan dos que
 * no había en bodega, el cliente ya no las quiere y nadie las va a mandar.
 * Hasta ahora ese pedido se quedaba «parcial» para siempre, ensuciando la
 * lista de lo que de verdad está pendiente por despachar.
 *
 * Cerrar NO es anular y NO es despachar: lo pendiente sigue pendiente y
 * sigue contando en los informes de no entregas. Lo único que cambia es que
 * el pedido deja de estar en curso.
 *
 * Por eso se guarda el motivo. Un pedido cerrado con faltantes es una venta
 * que no se hizo, y la pregunta que viene después —¿por qué?— solo se puede
 * contestar si alguien la escribió en el momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable()->after('notes');
            $table->foreignId('closed_by_user_id')->nullable()->after('closed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('close_reason')->nullable()->after('closed_by_user_id');

            // Los informes de pendientes filtran por aquí.
            $table->index(['company_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_taking_orders', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'closed_at']);
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropColumn(['closed_at', 'close_reason']);
        });
    }
};
