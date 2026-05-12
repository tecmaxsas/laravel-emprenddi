<?php

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\InventoryOpening;
use Illuminate\Support\Facades\DB;

class InventoryOpeningNumberer
{
    public function next(Company $company, string $prefix = 'SI'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('io:'.$company->id.':'.$prefix)]);
            }

            $last = InventoryOpening::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
