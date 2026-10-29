<?php

namespace App\Services\Hotel;

use App\Models\Company;
use App\Models\Hotel\Stay;
use Illuminate\Support\Facades\DB;

/**
 * Consecutivo del folio, por (empresa, prefijo).
 *
 * El folio necesita un número que recepción pueda decir en voz alta —«el
 * folio H-000142»— y que no se repita: dos estadías con el mismo número hacen
 * imposible saber a cuál corresponde un consumo cargado por teléfono desde el
 * restaurante.
 *
 * Mismo patrón de candado que los demás numeradores del sistema. No es el
 * consecutivo de la factura: ese lo reserva DocumentNumberer con la
 * resolución de la DIAN, y solo al facturar.
 *
 * Cuenta sin filtrar los borrados a propósito: un folio anulado se queda con
 * su número. Reutilizarlo dejaría dos documentos distintos con el mismo
 * número en el histórico.
 */
class HotelStayNumberer
{
    public function next(Company $company, string $prefix = 'H'): int
    {
        return DB::transaction(function () use ($company, $prefix) {
            $esPostgres = DB::connection()->getDriverName() === 'pgsql';

            if ($esPostgres) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('hstay:'.$company->id.':'.$prefix)]);
            }

            $ultimo = Stay::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('prefix', $prefix)
                ->when(! $esPostgres, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $ultimo) + 1;
        });
    }
}
