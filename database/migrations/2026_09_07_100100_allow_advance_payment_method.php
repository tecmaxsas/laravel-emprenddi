<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permite registrar un pago cuyo origen es un anticipo del cliente.
 *
 * `payments.payment_method` se creo como enum y el check de Postgres no admite
 * valores nuevos, asi que hay que reescribirlo. Se agregan tambien `gift_card`
 * y `advance`: el primero ya estaba en el modelo pero nunca llego a la
 * restriccion, asi que un pago con tarjeta regalo tampoco cabia.
 */
return new class extends Migration
{
    private const METHODS = [
        'cash',
        'bank_transfer',
        'check',
        'credit_card',
        'debit_card',
        'electronic',
        'credit_note',
        'gift_card',
        'advance',
        'other',
    ];

    public function up(): void
    {
        $this->replaceCheck(self::METHODS);
    }

    public function down(): void
    {
        $this->replaceCheck(array_values(array_diff(self::METHODS, ['gift_card', 'advance'])));
    }

    /** @param  list<string>  $methods */
    private function replaceCheck(array $methods): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $valores = implode(', ', array_map(fn ($m) => "'".$m."'", $methods));

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_method_check');
        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_payment_method_check
             CHECK (payment_method::text = ANY (ARRAY[{$valores}]::text[]))"
        );
    }
};
