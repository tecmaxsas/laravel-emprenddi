<?php

namespace App\Services\Purchases;

use App\Models\Company;
use App\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;

class PurchaseReturnNumberer
{
    public function next(Company $company, string $prefix = 'DEV'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('pr:'.$company->id.':'.$prefix)]);
            }

            $last = PurchaseReturn::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
