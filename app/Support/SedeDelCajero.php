<?php

namespace App\Support;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashRegisterSession;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * La sede en la que abre caja un cajero que no puede elegirla.
 *
 * El POS (dd1262c) le impone a quien no es admin/manager la sede PRINCIPAL de
 * la empresa. Con dos locales, el cajero del segundo terminaba vendiendo con
 * el inventario, la numeracion y el arqueo del primero.
 *
 * Lo correcto seria que PosTerminal::sedePorDefecto() consultara
 * users.pos_location_id, pero ese archivo es de root y no se puede editar
 * desde aqui. Mientras tanto se resuelve con dos enganches que NO dependen de
 * el:
 *
 *  - after('mount') de Livewire: el formulario de apertura ya muestra la sede
 *    asignada en vez de la principal.
 *  - CashRegisterSession::creating: la sesion se graba en la sede asignada
 *    aunque PosTerminal la haya forzado a la principal. Esta es la que manda;
 *    cubre tambien hotel y parqueadero.
 *
 * Cuando sedePorDefecto() lea pos_location_id, esta clase sobra.
 */
class SedeDelCajero
{
    /**
     * Id de la sede asignada si el usuario NO puede elegir sede y tiene una
     * asignada activa de su empresa. Null en cualquier otro caso (se respeta
     * lo que haya decidido la pantalla).
     */
    public static function impuestaPara(?User $user): ?int
    {
        if (! $user || ! $user->pos_location_id) {
            return null;
        }

        if ($user->hasAnyRole(['admin', 'manager'])) {
            return null;
        }

        return Location::query()
            ->where('company_id', $user->company_id)
            ->where('active', true)
            ->whereKey($user->pos_location_id)
            ->value('id');
    }

    public static function registrar(): void
    {
        \Livewire\after('mount', function ($component) {
            if ($component instanceof PosTerminal
                && ! $component->session_id
                && ($sede = self::impuestaPara(Auth::user()))) {
                $component->openingLocationId = $sede;
            }
        });

        CashRegisterSession::creating(function (CashRegisterSession $session) {
            $cajero = $session->cashier_user_id ? User::find($session->cashier_user_id) : null;

            if ($sede = self::impuestaPara($cajero)) {
                $session->location_id = $sede;
            }
        });
    }
}
