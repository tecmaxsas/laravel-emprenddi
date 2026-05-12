<?php

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\InventoryTransfer;
use Illuminate\Support\Facades\DB;

class InventoryTransferNumberer
{
    public function next(Company $company, string $prefix = 'TR'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('it:'.$company->id.':'.$prefix)]);
            }

            $last = InventoryTransfer::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
