<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\SaleInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Genera el siguiente consecutivo (compañía, prefijo) para facturas de venta.
 *
 * NOTA: este es el numerador "interno" que se usa cuando la sede aún no tiene
 * resolución DIAN asignada. Cuando arrancamos a postear (Iter 2) y la sede
 * tiene una LocationResolution activa, debemos usar
 * LocationResolution::reserveNextNumber() para tomar el consecutivo del rango
 * autorizado por DIAN, no éste.
 */
class SaleInvoiceNumberer
{
    public function next(Company $company, string $prefix = 'FV'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $last = SaleInvoice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->max('number');

            return ((int) $last) + 1;
        });
    }
}
