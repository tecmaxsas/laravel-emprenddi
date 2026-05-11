<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

class QuotationNumberer
{
    public function next(Company $company, string $prefix = 'COT'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('qt:'.$company->id.':'.$prefix)]);
            }

            $last = Quotation::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
