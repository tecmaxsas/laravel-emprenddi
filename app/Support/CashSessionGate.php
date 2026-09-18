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
     * Turno al que entra la plata que se está recibiendo AHORA.
     *
     * La regla es una sola y vale para ventas, compras, gastos y restaurante:
     * el dinero entra al cajón que está abierto cuando se recibe, no al del
     * documento. El documento puede haber nacido ayer, en otro turno o fuera
     * del POS; quien responde por los billetes es quien los tiene en la mano.
     *
     * Vivía duplicada en cada motor y por eso llegó a divergir: la factura de
     * una orden de restaurante se quedaba con la caja del mesero que tomó el
     * pedido en la tablet, mientras el pago ya usaba la del cajero que cobró.
     * Una sola función para que no puedan volver a separarse.
     *
     * $fallback es el turno del documento: se conserva solo si quien registra
     * no tiene caja abierta —un administrador cobrando una transferencia
     * desde su escritorio— para no perder la referencia.
     */
    public static function receivingSessionId(?int $fallback = null): ?int
    {
        return self::currentOpenSession()?->id ?? $fallback;
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
