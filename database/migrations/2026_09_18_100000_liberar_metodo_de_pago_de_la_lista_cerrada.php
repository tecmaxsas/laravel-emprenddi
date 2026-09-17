<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los métodos de pago los define cada empresa, no la base.
 *
 * `payments.payment_method` tenía un CHECK con la lista de fábrica —cash,
 * bank_transfer, check, credit_card, debit_card, electronic, credit_note,
 * gift_card, advance, other— de cuando esa era la única lista que existía.
 *
 * Pero hay una pantalla, Configuración → Métodos de pago, donde la empresa crea
 * los suyos: Nequi, Daviplata, un convenio propio. El campo del código es libre
 * y el formulario hasta sugiere `my_voucher` como ejemplo. El método se crea sin
 * problema, aparece en las listas desplegables, el cajero lo elige... y el pago
 * revienta con un error 500 al guardarse, porque la base rechaza el código.
 *
 * Es la misma clase de problema que tuvo `journal_entries.type`, pero aquí no
 * se arregla ampliando la lista: los valores válidos son datos de cada empresa
 * y no se pueden enumerar en una restricción. Se quita el CHECK.
 *
 * Lo que sí queda garantizado es que el código exista en `payment_methods` de
 * esa empresa, que es donde esa regla puede vivir de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_method_check');
    }

    public function down(): void
    {
        // Solo se puede volver a poner si ningun pago quedo por fuera de la
        // lista vieja. Reponerlo a ciegas dejaria la tabla en un estado que la
        // propia restriccion considera invalido.
        $fuera = DB::table('payments')
            ->whereNotIn('payment_method', [
                'cash', 'bank_transfer', 'check', 'credit_card', 'debit_card',
                'electronic', 'credit_note', 'gift_card', 'advance', 'other',
            ])
            ->count();

        if ($fuera > 0) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT payments_payment_method_check
            CHECK (payment_method IN (
                'cash', 'bank_transfer', 'check', 'credit_card', 'debit_card',
                'electronic', 'credit_note', 'gift_card', 'advance', 'other'
            ))
        SQL);
    }
};
