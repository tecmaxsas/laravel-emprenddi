<?php

namespace App\Support;

use App\Models\Account;
use App\Models\CashMovement;
use Illuminate\Support\Facades\Auth;

/**
 * Cuentas contables que el usuario no deberia tener que elegir.
 *
 * Una empresa **sin el modulo de contabilidad** no tiene por que ver una seccion
 * de imputacion contable pidiendole la cuenta de gasto o la contrapartida de un
 * ajuste de inventario: no lleva libros, no tiene contador, y esas preguntas no
 * significan nada para quien solo quiere registrar que pago el arriendo.
 *
 * Pero el asiento **se genera igual**, y las columnas son obligatorias en la
 * base. Asi que la cuenta hay que resolverla por debajo, no dejar de pedirla.
 *
 * El plan de cuentas se siembra al crear la empresa, con modulo o sin el, asi
 * que las cuentas de aqui existen. Se buscan por codigo y no por id porque el
 * id cambia entre empresas.
 *
 * Es el mismo criterio que ya seguia `PaymentAccountResolver` para la cuenta
 * donde entra el dinero; esto cubre las que le faltaban.
 */
class DefaultAccounts
{
    /**
     * Cuenta de gasto por defecto.
     *
     * 5195 «Diversos» es la cuenta de gastos generales del PUC: es donde un
     * contador pondria un gasto sin clasificar, y es la respuesta honesta
     * cuando la empresa no clasifica nada porque no lleva contabilidad.
     */
    public static function gasto(?int $companyId = null): ?int
    {
        return self::primeraQueExista(['5195', '5295', '5135'], $companyId)
            // Si el PUC vino recortado, cualquier cuenta de gastos sirve mas
            // que dejar el gasto sin poder guardarse.
            ?? self::primeraDeClase('5', $companyId);
    }

    /**
     * Contrapartida de un ajuste de inventario.
     *
     * Una entrada es una recuperacion —aparecio mercancia que no estaba
     * contada— y una salida es una perdida. No son la misma cuenta ni el mismo
     * signo, y ponerlas al reves deja el estado de resultados al contrario.
     *
     * @param  string  $direccion  'in' para entrada, cualquier otra para salida.
     */
    public static function contrapartidaDeAjuste(string $direccion, ?int $companyId = null): ?int
    {
        if ($direccion === 'in') {
            return self::primeraQueExista(['4255', '4295'], $companyId)
                ?? self::primeraDeClase('4', $companyId);
        }

        return self::primeraQueExista(['5295', '5195'], $companyId)
            ?? self::primeraDeClase('5', $companyId);
    }

    /**
     * Contrapartida de un movimiento directo de caja.
     *
     * La decide el MOTIVO, y eso importa: sacar plata a la caja fuerte no es
     * un gasto —el dinero sigue siendo del negocio y solo cambió de sitio—
     * mientras que pagarle al domiciliario sí lo es. Mandarlas a la misma
     * cuenta dejaría el estado de resultados contando como gasto plata que
     * está guardada en la caja fuerte.
     *
     * Los códigos de cada motivo viven en `CashMovement::REASONS`, junto al
     * motivo que los explica. Aquí solo se resuelven contra el PUC de la
     * empresa, con el mismo criterio que el resto de este archivo: el primero
     * que exista, porque el plan sembrado puede venir recortado.
     */
    public static function contrapartidaDeMovimientoDeCaja(string $motivo, ?int $companyId = null): ?int
    {
        $codigos = CashMovement::REASONS[$motivo]['cuentas'] ?? [];

        if ($codigos === []) {
            return null;
        }

        $id = self::primeraQueExista($codigos, $companyId);

        if ($id) {
            return $id;
        }

        // Sin la cuenta propia del motivo, se cae a la clase que le
        // corresponde por su primer código: así una entrada no termina
        // contabilizada contra una cuenta de gasto.
        return self::primeraDeClase(substr($codigos[0], 0, 1), $companyId);
    }

    /**
     * La cuenta de un codigo del PUC, garantizando que acepte movimientos.
     *
     * LO QUE ESTO ARREGLA, Y POR QUE IMPORTA.
     *
     * Veintiseis sitios buscaban la cuenta asi:
     *
     *     Account::where('company_id', $id)->where('code', '1435')->value('id')
     *
     * y le pasaban el resultado a un asiento. En un plan de cuentas donde
     * `1435` es una hoja, funciona. Pero `1435` es una cuenta MAYOR —tiene
     * debajo `143505 Inventario general`— y en los planes donde esta sembrada
     * asi, el asiento se iba contra la mayor.
     *
     * Contabilizar contra una cuenta mayor no es un detalle de forma: un
     * contador lo rechaza, los informes por nivel quedan contando dos veces, y
     * el propio sistema ya decia que no se podia —`accepts_movements = false`
     * esta ahi justamente para eso— pero nadie le preguntaba.
     *
     * Peor todavia: solo se rompe en los planes donde ese codigo es mayor, asi
     * que funciona en desarrollo y falla en la empresa que tiene el PUC
     * completo. Ese es el tipo de error que se descubre en una revision
     * contable, meses despues.
     *
     * Si el codigo exacto no acepta movimientos, se baja a la primera de sus
     * hijas que si —`1435` → `143505`—, que es lo que un contador haria.
     */
    public static function porCodigo(string $codigo, ?int $companyId = null): ?int
    {
        $companyId ??= Auth::user()?->company_id;

        if (! $companyId || $codigo === '') {
            return null;
        }

        $exacta = Account::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $codigo)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->value('id');

        if ($exacta) {
            return (int) $exacta;
        }

        // La hija de menor codigo que si reciba movimientos. Se ordena por
        // codigo para que la respuesta sea siempre la misma: una cuenta que
        // cambia entre ejecuciones parte la contabilidad en dos mitades que no
        // cuadran.
        $hija = Account::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', $codigo.'%')
            ->where('code', '!=', $codigo)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->orderBy('code')
            ->value('id');

        return $hija ? (int) $hija : null;
    }

    /**
     * @param  list<string>  $codigos  En orden de preferencia.
     */
    private static function primeraQueExista(array $codigos, ?int $companyId = null): ?int
    {
        foreach ($codigos as $codigo) {
            // Delega en `porCodigo` para que la regla de «solo cuentas que
            // reciben movimientos» este escrita una vez. Antes se repetia
            // aqui, y repetida es como empezo el problema que `porCodigo`
            // viene a arreglar.
            $id = self::porCodigo($codigo, $companyId);

            if ($id) {
                return $id;
            }
        }

        return null;
    }

    private static function primeraDeClase(string $clase, ?int $companyId = null): ?int
    {
        $companyId ??= Auth::user()?->company_id;

        if (! $companyId) {
            return null;
        }

        $id = Account::query()
            ->where('company_id', $companyId)
            ->where('code', 'like', $clase.'%')
            ->where('accepts_movements', true)
            ->where('active', true)
            ->orderBy('code')
            ->value('id');

        return $id ? (int) $id : null;
    }
}
