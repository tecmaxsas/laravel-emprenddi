<?php

namespace App\Support;

use App\Models\Account;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Auth;

/**
 * Cuenta contable donde entra el dinero al cobrar con un metodo de pago.
 *
 * El cajero NO deberia elegirla: es una decision contable que el administrador
 * configura una vez en Metodos de pago. Con el modulo de contabilidad apagado
 * ni siquiera deberia ver el campo — igual hay que resolverla, porque el
 * asiento se genera de todos modos por debajo.
 *
 * Los tres POS resolvian esto por su cuenta y con criterios distintos: retail
 * miraba el metodo de pago, restaurante tambien pero con otro fallback, y
 * parqueadero siempre buscaba la caja de efectivo sin importar como pagara el
 * cliente. Ahora es un solo camino.
 */
class PaymentAccountResolver
{
    /**
     * @param  string|null  $method  Codigo del metodo (cash, card, transfer...)
     */
    public static function forMethod(?string $method, ?int $companyId = null): ?int
    {
        $companyId ??= Auth::user()?->company_id;

        if (! $companyId) {
            return null;
        }

        // 1. Lo que el administrador configuro para ese metodo. Es la fuente
        //    de verdad: si esta puesta, manda.
        if ($method) {
            $configured = PaymentMethod::query()
                ->where('company_id', $companyId)
                ->where('code', $method)
                ->where('active', true)
                ->value('account_id');

            if ($configured) {
                return (int) $configured;
            }
        }

        // 2. Heuristica por naturaleza del metodo: el efectivo entra a caja,
        //    lo demas a bancos.
        $prefijo = $method === 'cash' ? '1105' : '1110';

        $porPrefijo = self::firstMovementAccount($companyId, $prefijo);

        if ($porPrefijo) {
            return $porPrefijo;
        }

        // 3. Ultimo recurso: cualquier cuenta de disponible (11). Evita que el
        //    cobro se caiga por no encontrar la cuenta exacta del PUC.
        return self::firstMovementAccount($companyId, '11');
    }

    protected static function firstMovementAccount(int $companyId, string $prefijo): ?int
    {
        $id = Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where('code', 'like', $prefijo.'%')
            ->orderBy('code')
            ->value('id');

        return $id ? (int) $id : null;
    }
}
