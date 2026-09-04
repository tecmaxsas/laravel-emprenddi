<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartera del cliente: saldo de apertura y anticipos.
 *
 * SALDO DE APERTURA
 * Lo que el cliente ya debia cuando la empresa empezo a usar Emprenddi. No
 * corresponde a ninguna factura de aqui, asi que va en el tercero y aparece
 * como primera linea del estado de cuenta.
 *
 * ANTICIPOS
 * Hoy un pago siempre va contra una factura y no puede exceder su saldo, lo
 * que evita el descuadre clasico de un cliente con saldo a favor y deuda al
 * mismo tiempo. Pero deja fuera un caso real: el cliente que abona antes de
 * que exista la venta.
 *
 * El anticipo se guarda aparte porque es un pasivo (plata recibida que
 * todavia no es ingreso), no un menor valor de la cartera. Cuando se aplica a
 * una factura se crea un pago normal contra ella, de modo que toda la logica
 * de saldos y estados sigue siendo una sola.
 *
 * `applied_amount` nunca puede pasar de `amount`: eso es justamente lo que
 * permitiria que el saldo a favor y la deuda convivan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('third_parties', function (Blueprint $table) {
            $table->decimal('opening_balance', 16, 2)->default(0)->after('credit_limit');
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
        });

        Schema::create('customer_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->decimal('amount', 16, 2);
            $table->decimal('applied_amount', 16, 2)->default(0);

            $table->string('payment_method', 40)->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable();

            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'third_party_id', 'date']);
        });

        // De que anticipo salio cada pago: sin esto no se puede deshacer una
        // aplicacion ni explicar de donde vino la plata en el estado de cuenta.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('customer_advance_id')->nullable()->after('third_party_id')
                ->constrained('customer_advances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_advance_id');
        });

        Schema::dropIfExists('customer_advances');

        Schema::table('third_parties', function (Blueprint $table) {
            $table->dropColumn(['opening_balance', 'opening_balance_date']);
        });
    }
};
