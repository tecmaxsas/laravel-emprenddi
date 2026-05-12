<?php

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\InventoryAdjustment;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentNumberer
{
    public function next(Company $company, string $prefix = 'AJ'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('ia:'.$company->id.':'.$prefix)]);
            }

            $last = InventoryAdjustment::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
