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

    /**
     * Los tipos ya resueltos, por empresa.
     *
     * @var array<int, array<string, string>>
     */
    private static array $tipos = [];

    /**
     * Si ese método es efectivo, o sea plata que queda en el cajón.
     *
     * Parece una pregunta trivial y no lo es: el sistema la respondía
     * comparando el código contra el literal `'cash'`. Eso funciona para el
     * método que trae el sistema, pero una empresa puede crear el suyo —«Caja
     * 2», «Efectivo domicilios»— con otro código y tipo `cash`. Con la
     * comparación literal, ese dinero no entraba en «Esperado en caja» y el
     * cajero aparecía sobrando al arquear.
     *
     * La respuesta está en el **tipo** del método, que es lo que describe su
     * naturaleza; el código es solo un identificador. Si el método no está
     * configurado —una empresa recién creada, o un código viejo que ya no
     * existe— se cae a la comparación de antes, que es lo único que queda.
     */
    public static function esEfectivo(?string $codigo, ?int $companyId = null): bool
    {
        if (! $codigo) {
            return false;
        }

        $companyId ??= Auth::user()?->company_id;

        if ($companyId) {
            self::$tipos[$companyId] ??= PaymentMethod::query()
                ->where('company_id', $companyId)
                ->pluck('type', 'code')
                ->all();

            if (isset(self::$tipos[$companyId][$codigo])) {
                return self::$tipos[$companyId][$codigo] === 'cash';
            }
        }

        return $codigo === 'cash';
    }

    /** Para las pruebas, que cambian métodos entre casos. */
    public static function olvidarCache(): void
    {
        self::$cache = [];
        self::$tipos = [];
    }
}
