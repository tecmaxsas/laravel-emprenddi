<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entradas y salidas directas de la caja.
 *
 * La plata del cajón se mueve por más razones que las ventas: hay que pagarle
 * al domiciliario, sacar lo acumulado a la caja fuerte, meter cambio porque se
 * acabaron las monedas, darle un adelanto a alguien. Hasta ahora nada de eso
 * tenía dónde registrarse, así que el cajero cuadraba «a mano» al cerrar y la
 * diferencia aparecía como faltante o sobrante sin que nadie supiera por qué.
 *
 * Un faltante de $50.000 que en realidad fue el pago del domicilio es peor que
 * un faltante de verdad: enseña a todo el mundo que las diferencias de caja no
 * significan nada.
 *
 * TRES DECISIONES QUE SOSTIENEN ESTO:
 *
 * 1. **Siempre es efectivo.** Un movimiento «directo a la caja» es, por
 *    definición, plata que entra o sale del cajón. Una transferencia no toca
 *    la caja física y no tiene nada que hacer aquí.
 *
 * 2. **Siempre genera asiento.** Si el efectivo baja y los libros no lo
 *    explican, el módulo de contabilidad empieza a mentir. La contrapartida la
 *    decide el MOTIVO —sacar a la caja fuerte no es lo mismo que pagar un
 *    domicilio— y se resuelve por debajo, para que el cajero no tenga que
 *    elegir una cuenta del PUC.
 *
 * 3. **No se borra: se anula.** Con motivo escrito y reversando el asiento.
 *    Un movimiento de caja que desaparece es exactamente la forma de sacar
 *    plata sin dejar rastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Un movimiento pertenece a un turno. Sin turno no hay caja que
            // mover, y es lo que hace que aparezca en el arqueo correcto.
            $table->foreignId('cash_register_session_id')
                ->constrained('cash_register_sessions')->restrictOnDelete();

            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->string('direction', 3);                 // in | out
            $table->string('reason', 30);                   // ver CashMovement::REASONS

            // Para qué fue. Obligatorio: es lo único que explica la plata
            // cuando alguien revise el turno dentro de tres meses.
            $table->string('concept', 180);

            $table->decimal('amount', 18, 2);

            // La cuenta de caja de donde sale o a donde entra, y la del otro
            // lado del asiento. Se guardan en el movimiento y no se recalculan
            // al consultarlo: si mañana cambia la configuración, lo contabilizado
            // tiene que seguir diciendo contra qué se contabilizó.
            $table->foreignId('cash_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('counterpart_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            // A quién se le pagó o de quién vino, cuando se sabe.
            $table->foreignId('third_party_id')->nullable()
                ->constrained('third_parties')->nullOnDelete();

            $table->text('notes')->nullable();

            // Anulación, no borrado.
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // El índice del arqueo: todos los movimientos vivos de un turno.
            $table->index(['cash_register_session_id', 'voided_at'], 'cash_movements_turno_idx');
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'direction', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
