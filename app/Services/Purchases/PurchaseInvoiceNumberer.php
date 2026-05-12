<?php

namespace App\Services\Purchases;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Consecutivo interno (compañía, prefijo) para facturas de compra. Aplica
 * el mismo patrón advisory-lock-pgsql del resto de numeradores: Postgres
 * no permite FOR UPDATE sobre aggregates.
 */
class PurchaseInvoiceNumberer
{
    public function next(Company $company, string $prefix = 'FC'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('pi:'.$company->id.':'.$prefix)]);
            }

            $last = PurchaseInvoice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
