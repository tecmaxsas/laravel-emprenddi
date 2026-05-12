<?php

namespace App\Services\Expenses;

use App\Models\Company;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * Consecutivo interno (compañía, prefijo) para gastos. Mismo patrón Postgres
 * de los demás numeradores: advisory lock por scope para evitar el conflicto
 * de FOR UPDATE sobre aggregates.
 */
class ExpenseNumberer
{
    public function next(Company $company, string $prefix = 'EXP'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('exp:'.$company->id.':'.$prefix)]);
            }

            $last = Expense::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
