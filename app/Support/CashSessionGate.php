<?php

namespace App\Support;

use App\Models\CashRegisterSession;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Gate compartido para flujos que requieren una sesión de caja abierta:
 * ventas POS, compras, gastos. Mantiene la regla de negocio en un solo
 * lugar — si más adelante cambia (p. ej. permitir cierto rol sin caja),
 * se ajusta acá.
 */
class CashSessionGate
{
    /**
     * Sesión abierta del usuario actual, o null si no tiene.
     */
    public static function currentOpenSession(): ?CashRegisterSession
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        return CashRegisterSession::query()
            ->where('cashier_user_id', $user->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Hay alguna sesión abierta para el usuario actual.
     */
    public static function hasOpenSession(): bool
    {
        return self::currentOpenSession() !== null;
    }

    /**
     * Devuelve la sesión abierta o lanza una excepción con mensaje legible.
     * Útil dentro de engines / actions que NO deben proceder sin caja.
     */
    public static function requireOpenSession(): CashRegisterSession
    {
        $session = self::currentOpenSession();
        if (! $session) {
            throw new RuntimeException(
                'Debes abrir una caja registradora antes de registrar esta operación.'
            );
        }
        return $session;
    }
}
