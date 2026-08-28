<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * A que POS pertenece este usuario.
 *
 * Un mismo negocio puede tener varios modulos activos, asi que el orden de
 * prioridad decide cual es "su" punto de venta:
 *
 *   1. Restaurante — si el modulo esta activo y tiene restaurant.use
 *   2. Parqueadero — el Terminal ES su POS, por eso gana sobre el retail
 *   3. POS retail  — si tiene pos.use
 *
 * Lo usan el boton POS del topbar y la apertura de caja, que hasta ahora
 * dejaba a todo el mundo en el POS retail sin importar su negocio.
 */
class PosDestination
{
    public const RESTAURANT = 'restaurant';

    public const PARKING = 'parking';

    public const RETAIL = 'retail';

    /** @return self::RESTAURANT|self::PARKING|self::RETAIL|null */
    public static function resolve(): ?string
    {
        $user = Auth::user();

        if (ModuleGate::active('restaurant') && $user?->can('restaurant.use')) {
            return self::RESTAURANT;
        }

        if (ModuleGate::active('parking') && $user?->can('parking.use')) {
            return self::PARKING;
        }

        return $user?->can('pos.use') ? self::RETAIL : null;
    }

    /** URL del POS que le corresponde. Null si no puede usar ninguno. */
    public static function url(): ?string
    {
        return self::urlFor(self::resolve());
    }

    public static function urlFor(?string $destino): ?string
    {
        return match ($destino) {
            self::RESTAURANT => route('filament.app.pages.restaurant-pos'),
            self::PARKING => route('filament.app.pages.parking'),
            self::RETAIL => route('filament.app.pages.pos'),
            default => null,
        };
    }
}
