<?php

namespace App\Services\Purchases;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Consecutivo interno (compañía, prefijo) para documentos soporte.
 * Mismo patrón advisory-lock-pgsql que PurchaseInvoiceNumberer.
 *
 * Mientras no se conecte la resolución DIAN tipo 4, el documento soporte
 * numera localmente. Al cablear la transmisión electrónica el consecutivo
 * pasará a reservarse del rango de la resolución.
 */
class SupportDocumentNumberer
{
    public function next(Company $company, string $prefix = 'DS'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('ds:'.$company->id.':'.$prefix)]);
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
