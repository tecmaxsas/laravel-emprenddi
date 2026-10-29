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
 *   3. Hotel       — la recepcion ES su POS, misma razon
 *   4. POS retail  — si tiene pos.use
 *
 * Lo usan el boton POS del topbar y la apertura de caja, que hasta ahora
 * dejaba a todo el mundo en el POS retail sin importar su negocio.
 */
class PosDestination
{
    public const RESTAURANT = 'restaurant';

    public const PARKING = 'parking';

    public const HOTEL = 'hotel';

    public const RETAIL = 'retail';

    /**
     * Los modulos verticales que se llevan al usuario fuera del POS retail,
     * en orden de prioridad: modulo => permiso que hace falta.
     *
     * Vive aqui porque esta lista se consulta desde dos sitios muy distintos:
     * `resolve()`, que decide a donde va el usuario, y el apoyo de pruebas
     * `FuerzaPosDeRetail`, que necesita apagar exactamente estos modulos para
     * poder montar el POS retail.
     *
     * Escrita dos veces no falla: se queda vieja. Al agregar el hotel como
     * tercer destino, la copia de las pruebas siguio conociendo solo dos y
     * doce pruebas del POS retail empezaron a fallar con «Invalid Livewire
     * snapshot structure», que es como se ve un redirect desde una prueba de
     * Livewire — nada que ver con el codigo que estaban probando.
     */
    public const MODULOS_QUE_DESVIAN = [
        self::RESTAURANT => 'restaurant.use',
        self::PARKING => 'parking.use',
        self::HOTEL => 'hotel.use',
    ];

    /** @return self::RESTAURANT|self::PARKING|self::HOTEL|self::RETAIL|null */
    public static function resolve(): ?string
    {
        $user = Auth::user();

        foreach (self::MODULOS_QUE_DESVIAN as $destino => $permiso) {
            if (ModuleGate::active($destino) && $user?->can($permiso)) {
                return $destino;
            }
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
            self::HOTEL => route('filament.app.pages.hotel'),
            self::RETAIL => route('filament.app.pages.pos'),
            default => null,
        };
    }
}
