<?php

namespace App\Services\Onboarding;

use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Crea los 8 métodos de pago estándar para una empresa. Idempotente —
 * firstOrCreate por (company, code). Resuelve las cuentas contables
 * (110505 caja, 1110% banco) y las asocia donde aplica.
 *
 * Si el PUC todavía no fue provisionado (o falta una cuenta), account_id
 * queda null — el usuario lo configura después manualmente.
 */
class PaymentMethodProvisioner
{
    public function provision(Company $company): int
    {
        $defaults = [
            ['code' => 'cash',          'name' => 'Efectivo',               'type' => 'cash',          'account' => 'cash', 'requires_ref' => false, 'sort' => 1],
            ['code' => 'debit_card',    'name' => 'Tarjeta débito',         'type' => 'debit_card',    'account' => 'bank', 'requires_ref' => false, 'sort' => 2],
            ['code' => 'credit_card',   'name' => 'Tarjeta de crédito',     'type' => 'credit_card',   'account' => 'bank', 'requires_ref' => false, 'sort' => 3],
            ['code' => 'bank_transfer', 'name' => 'Transferencia bancaria', 'type' => 'bank_transfer', 'account' => 'bank', 'requires_ref' => true,  'sort' => 4],
            ['code' => 'electronic',    'name' => 'PSE / Pago electrónico', 'type' => 'electronic',    'account' => 'bank', 'requires_ref' => true,  'sort' => 5],
            ['code' => 'check',         'name' => 'Cheque',                 'type' => 'check',         'account' => 'bank', 'requires_ref' => true,  'sort' => 6],
            ['code' => 'credit_note',   'name' => 'Nota crédito aplicada',  'type' => 'credit_note',   'account' => null,   'requires_ref' => true,  'sort' => 7],
            ['code' => 'other',         'name' => 'Otro',                   'type' => 'other',         'account' => null,   'requires_ref' => false, 'sort' => 8],
        ];

        $created = 0;
        DB::transaction(function () use ($company, $defaults, &$created) {
            $cashAccountId = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', '110505')
                ->where('accepts_movements', true)
                ->value('id');

            $bankAccountId = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', 'like', '1110%')
                ->where('accepts_movements', true)
                ->orderBy('code')
                ->value('id');

            foreach ($defaults as $def) {
                $accountId = match ($def['account']) {
                    'cash' => $cashAccountId,
                    'bank' => $bankAccountId,
                    default => null,
                };

                $rec = PaymentMethod::withoutGlobalScopes()->firstOrCreate(
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
                if ($rec->wasRecentlyCreated) {
                    $created++;
                }
            }
        });

        return $created;
    }
}
