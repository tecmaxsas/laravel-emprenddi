<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Auth;

/**
 * Las formas de pago que puede elegir un usuario.
 *
 * Son **las que la empresa configuró**, no la lista de fábrica. Una empresa que
 * cobra por Nequi, Daviplata o un convenio propio los crea en Configuración →
 * Métodos de pago, y si una pantalla no los lee, el cajero no puede registrar el
 * cobro como realmente entró la plata: lo mete como «Otro» y el arqueo deja de
 * cuadrar contra el extracto.
 *
 * La lista de fábrica queda solo como salvavidas para empresas recién creadas
 * que todavía no tienen métodos sembrados. Sin ella la pantalla saldría vacía y
 * no se podría cobrar nada.
 *
 * Vive aquí porque la regla ya estaba copiada en cuatro pantallas y en dos se
 * había quedado atrás —el estado de cuenta y el POS de restaurante mostraban
 * solo los de fábrica—. Una copia que se queda atrás no falla: hace que media
 * empresa trabaje con datos distintos a la otra media.
 */
class PaymentMethodOptions
{
    /**
     * Los nombres ya resueltos, por empresa.
     *
     * `nombre()` se llama dentro de bucles —un recibo con tres pagos, un listado
     * de cobros— y sin esto cada línea seria una consulta a la base.
     *
     * @var array<int, array<string, string>>
     */
    private static array $cache = [];

    /**
     * @return array<string, string> código => nombre
     */
    public static function para(?int $companyId = null): array
    {
        $companyId ??= Auth::user()?->company_id;

        if (! $companyId) {
            return Payment::PAYMENT_METHODS;
        }

        $configurados = PaymentMethod::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();

        return $configurados ?: Payment::PAYMENT_METHODS;
    }

    /**
     * El nombre legible de un código de forma de pago.
     *
     * Se usa al imprimir un recibo o al describir un asiento. Mira primero lo
     * que la empresa configuró: un método propio con código `nequi` se imprimía
     * como «nequi» en el tiquete del cliente porque solo se buscaba en la lista
     * de fábrica.
     */
    public static function nombre(?string $codigo, ?int $companyId = null): string
    {
        if (! $codigo) {
            return '';
        }

        $companyId ??= Auth::user()?->company_id;

        if ($companyId) {
            self::$cache[$companyId] ??= PaymentMethod::query()
                ->where('company_id', $companyId)
                ->pluck('name', 'code')
                ->all();

            if (isset(self::$cache[$companyId][$codigo])) {
                return self::$cache[$companyId][$codigo];
            }
        }

        return Payment::PAYMENT_METHODS[$codigo] ?? ucfirst($codigo);
    }

    /** Para las pruebas, que cambian métodos entre casos. */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }
}
