<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Asegura que cada empresa existente tenga los 8 métodos de pago estándar.
 *
 * Idempotente: usa firstOrCreate por (company_id, code). Se corre en cada deploy.
 *
 * Los códigos coinciden con los keys de la antigua constante
 * Payment::PAYMENT_METHODS para no romper pagos ya registrados.
 */
class DefaultPaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['code' => 'cash',           'name' => 'Efectivo',                  'type' => 'cash',           'account_code' => '110505', 'requires_ref' => false, 'sort' => 1],
            ['code' => 'debit_card',     'name' => 'Tarjeta débito',            'type' => 'debit_card',     'account_code' => '1110',   'requires_ref' => false, 'sort' => 2],
            ['code' => 'credit_card',    'name' => 'Tarjeta de crédito',        'type' => 'credit_card',    'account_code' => '1110',   'requires_ref' => false, 'sort' => 3],
            ['code' => 'bank_transfer',  'name' => 'Transferencia bancaria',    'type' => 'bank_transfer',  'account_code' => '1110',   'requires_ref' => true,  'sort' => 4],
            ['code' => 'electronic',     'name' => 'PSE / Pago electrónico',    'type' => 'electronic',     'account_code' => '1110',   'requires_ref' => true,  'sort' => 5],
            ['code' => 'check',          'name' => 'Cheque',                    'type' => 'check',          'account_code' => '1110',   'requires_ref' => true,  'sort' => 6],
            ['code' => 'credit_note',    'name' => 'Nota crédito aplicada',     'type' => 'credit_note',    'account_code' => null,     'requires_ref' => true,  'sort' => 7],
            ['code' => 'other',          'name' => 'Otro',                      'type' => 'other',          'account_code' => null,     'requires_ref' => false, 'sort' => 8],
        ];

        Company::query()->each(function (Company $company) use ($defaults) {
            // Resuelve cuentas comunes una sola vez por empresa
            $cashAccountId = Account::query()
                ->where('company_id', $company->id)
                ->where('code', '110505')
                ->where('accepts_movements', true)
                ->value('id');

            $bankAccountId = Account::query()
                ->where('company_id', $company->id)
                ->where('code', 'like', '1110%')
                ->where('accepts_movements', true)
                ->orderBy('code')
                ->value('id');

            foreach ($defaults as $def) {
                $accountId = match ($def['account_code']) {
                    '110505' => $cashAccountId,
                    '1110' => $bankAccountId,
                    default => null,
                };

                PaymentMethod::withoutGlobalScopes()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'code' => $def['code'],
                    ],
                    [
                        'name' => $def['name'],
                        'type' => $def['type'],
                        'account_id' => $accountId,
                        'requires_reference' => $def['requires_ref'],
                        'active' => true,
                        'sort_order' => $def['sort'],
                    ],
                );
            }
        });
    }
}
