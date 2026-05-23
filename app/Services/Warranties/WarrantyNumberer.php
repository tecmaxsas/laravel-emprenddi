<?php

namespace App\Services\Warranties;

use App\Models\Company;
use App\Models\Warranty;
use Illuminate\Support\Facades\DB;

/**
 * Numerador transaccional para tickets de garantía. Mismo patrón que
 * InventoryAdjustmentNumberer (advisory lock en PG, lockForUpdate en otros)
 * para no chocar al crear varios tickets en paralelo.
 */
class WarrantyNumberer
{
    public function next(Company $company, string $prefix = 'GAR'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('warr:'.$company->id.':'.$prefix)]);
            }

            $last = Warranty::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
