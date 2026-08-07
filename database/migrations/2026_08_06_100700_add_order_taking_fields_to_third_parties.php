<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos B2B para toma de pedidos. Los tercerqs (clientes) del modulo
 * order_taking necesitan datos que el ThirdParty base no tenia:
 *
 * - default_price_list_id: lista de precios asignada por defecto al cliente.
 * - delivery_horario: texto libre "Lun-Vier 8 a 12 y 2 a 5 pm".
 * - payment_terms: "Contado", "Credito 30 dias", etc. Texto libre.
 * - retention_percent: % ReteFuente que el cliente aplica en el pago.
 * - business_name: nombre comercial (distinto de razon social).
 *
 * Son campos opcionales, no rompen empresas que no usen el modulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->foreignId('default_price_list_id')->nullable()
                ->after('contact_person')
                ->constrained('order_taking_price_lists')->nullOnDelete();
            $table->string('business_name', 200)->nullable()->after('default_price_list_id');
            $table->string('delivery_horario', 150)->nullable()->after('business_name');
            $table->string('payment_terms', 100)->nullable()->after('delivery_horario');
            $table->decimal('retention_percent', 6, 4)->default(0)->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->dropForeign(['default_price_list_id']);
            $table->dropColumn([
                'default_price_list_id',
                'business_name',
                'delivery_horario',
                'payment_terms',
                'retention_percent',
            ]);
        });
    }
};
