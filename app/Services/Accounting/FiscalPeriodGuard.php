<?php

namespace App\Services\Accounting;

use App\Models\FiscalPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Guard centralizado para validar que una fecha cae en un período abierto.
 * Lo invocan boot::saving de JournalEntry, Payment e InventoryMovement.
 *
 * Caché por proceso: cierres son raros (mensuales) y se consulta muchas
 * veces durante operaciones; cacheamos por (company, year-month) para no
 * pegarle a la BD en cada save.
 */
class FiscalPeriodGuard
{
    /** @var array<string,bool> */
    protected static array $cache = [];

    /**
     * Lanza excepción si la fecha está en un período cerrado.
     */
    public static function ensureOpen(?int $companyId, string|\DateTimeInterface|null $date): void
    {
        if (! $companyId || ! $date) {
            return;
        }

        $d = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
        $key = $companyId.':'.$d->year.':'.$d->month;

        if (! array_key_exists($key, self::$cache)) {
            // Primero busca cierre específico del mes (year, month)
            $closed = FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->where('year', $d->year)
                ->where('month', $d->month)
                ->where('status', FiscalPeriod::STATUS_CLOSED)
                ->exists();

            // O un cierre anual (month=0) que cubre el año entero
            if (! $closed) {
                $closed = FiscalPeriod::query()
                    ->where('company_id', $companyId)
                    ->where('year', $d->year)
                    ->where('month', 0)
                    ->where('status', FiscalPeriod::STATUS_CLOSED)
                    ->exists();
            }

            self::$cache[$key] = $closed;
        }

        if (self::$cache[$key]) {
            throw new RuntimeException(sprintf(
                'El período %s de %d está cerrado contablemente. No se permiten movimientos con esa fecha.',
                FiscalPeriod::MONTHS_LABELS[$d->month] ?? '?',
                $d->year,
            ));
        }
    }

    /**
     * Invalida la caché — llamar al cerrar/reabrir un período.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
