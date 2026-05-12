<?php

namespace App\Services\Accounting;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use RuntimeException;

/**
 * Helper centralizado para resolver tasas de cambio. Las consultas son
 * frecuentes en flujos transaccionales — cacheamos por proceso por
 * (company, code, date).
 */
class ExchangeRateService
{
    /** @var array<string,float> */
    protected static array $cache = [];

    /**
     * Tasa de la moneda destino → moneda base de la empresa, en la
     * fecha indicada (o la más reciente anterior).
     *
     * Si code es la moneda base, retorna 1.0.
     * Si no hay tasa para esa fecha, busca la última anterior.
     * Si no hay ninguna, lanza excepción.
     */
    public function rateFor(int $companyId, string $code, string|\DateTimeInterface $date): float
    {
        $d = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
        $dateStr = $d->toDateString();
        $key = $companyId.':'.$code.':'.$dateStr;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $currency = Currency::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        if (! $currency) {
            throw new RuntimeException("Moneda {$code} no registrada para esta empresa.");
        }

        if ($currency->is_base) {
            return self::$cache[$key] = 1.0;
        }

        $rate = ExchangeRate::query()
            ->where('currency_id', $currency->id)
            ->whereDate('date', '<=', $dateStr)
            ->orderByDesc('date')
            ->value('rate');

        if (! $rate) {
            throw new RuntimeException(
                "No hay tasa de cambio registrada para {$code} en {$dateStr}. Registra una en Configuración → Monedas."
            );
        }

        return self::$cache[$key] = (float) $rate;
    }

    /**
     * Convierte un monto en moneda destino → moneda base.
     */
    public function toBase(int $companyId, float $amount, string $code, string|\DateTimeInterface $date): float
    {
        if ($amount == 0.0) return 0.0;
        return round($amount * $this->rateFor($companyId, $code, $date), 2);
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
