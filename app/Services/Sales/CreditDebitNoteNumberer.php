<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\CreditDebitNote;
use Illuminate\Support\Facades\DB;

class CreditDebitNoteNumberer
{
    public function next(Company $company, string $type, string $prefix): int
    {
        return DB::transaction(function () use ($company, $type, $prefix) {
            $last = CreditDebitNote::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('type', $type)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
