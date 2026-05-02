<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class JournalEntryNumberer
{
    /**
     * Devuelve el siguiente número consecutivo para (compañía, prefijo).
     * Garantiza unicidad usando lock pesimista sobre el contador.
     */
    public function next(Company $company, string $prefix = 'AS'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $last = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
