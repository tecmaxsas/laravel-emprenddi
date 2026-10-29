<?php

namespace App\Support;

use App\Services\Hotel\HotelProductProvisioner;
use App\Services\Parking\ParkingProductProvisioner;

/**
 * Los productos que el sistema se crea a sí mismo para poder facturar.
 *
 * No son mercancía ni servicios de catálogo: son el renglón con el que un
 * módulo lleva su cobro a una factura de venta. El parqueadero necesita uno
 * para cobrar la salida; el hotel, uno para cobrar la noche. Se crean solos al
 * activar el módulo, con precio 0 —el precio real sale de la tarifa en cada
 * caso— y el administrador nunca los escribió.
 *
 * Por qué existe esta lista: **no van en la vitrina.** El catálogo público es
 * lo que el negocio le muestra a sus clientes por un enlace, y «Alojamiento
 * — $0» ahí no es un producto, es una filtración de la plomería del sistema.
 * Al nacer el módulo de hoteles ese renglón empezó a aparecer en el catálogo
 * de cualquier hotel, y el del parqueadero llevaba haciendo lo mismo desde
 * antes sin que nadie lo hubiera notado.
 *
 * Vive aquí y no escrita dentro de la consulta del catálogo para que el
 * siguiente módulo que necesite su producto de facturación lo agregue en un
 * solo sitio.
 */
class ProductosInternos
{
    /**
     * Los códigos, tomados de cada provisionador.
     *
     * De los provisionadores y no copiados: el código está definido una vez,
     * donde se crea el producto. Copiarlo aquí sería dos verdades que se
     * pueden separar.
     *
     * @return list<string>
     */
    public static function codigos(): array
    {
        return [
            ParkingProductProvisioner::CODE,
            HotelProductProvisioner::CODE,
        ];
    }

    public static function es(?string $codigo): bool
    {
        return $codigo !== null && in_array($codigo, self::codigos(), true);
    }
}
