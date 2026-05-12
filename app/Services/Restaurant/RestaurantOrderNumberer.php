<?php

namespace App\Services\Restaurant;

use App\Models\Company;
use App\Models\Restaurant\Order;
use Illuminate\Support\Facades\DB;

/**
 * Consecutivo (company, prefix) para órdenes de restaurante.
 * Mismo patrón advisory-lock para Postgres que los demás numeradores.
 */
class RestaurantOrderNumberer
{
    public function next(Company $company, string $prefix = 'ORD'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('rord:'.$company->id.':'.$prefix)]);
            }

            $last = Order::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
