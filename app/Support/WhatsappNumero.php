<?php

namespace App\Support;

/**
 * El número en el formato que espera wa.me: solo dígitos, con indicativo.
 *
 * Parece una tontería y es justo la clase de regla que se escribe varias veces
 * y se separa. Estaba en dos sitios —el estado de cuenta y el catálogo
 * público— y **ya habían divergido**: uno rechazaba los números de menos de
 * siete dígitos y el otro armaba el enlace igual, así que un teléfono mal
 * digitado abría un chat de WhatsApp con un número que no existe. Nadie lo
 * reporta: el que lo ve asume que escribió mal y cierra.
 *
 * Diez dígitos que empiezan por 3 son un celular colombiano sin indicativo, y
 * se asume Colombia porque ahí están los clientes de la plataforma. Si alguna
 * vez deja de ser cierto, se cambia aquí.
 */
class WhatsappNumero
{
    /** Mínimo razonable para un teléfono. Por debajo es un dato incompleto. */
    private const MINIMO = 7;

    public static function para(?string $telefono): ?string
    {
        $numero = preg_replace('/\D+/', '', (string) $telefono);

        if (strlen((string) $numero) < self::MINIMO) {
            return null;
        }

        if (strlen($numero) === 10 && str_starts_with($numero, '3')) {
            return '57'.$numero;
        }

        return $numero;
    }

    /**
     * El enlace listo para abrir, con el mensaje ya escrito.
     *
     * Null si el número no sirve: es preferible no mostrar el botón a mostrar
     * uno que abre un chat vacío con un número inventado.
     */
    public static function enlace(?string $telefono, string $mensaje = ''): ?string
    {
        $numero = self::para($telefono);

        if (! $numero) {
            return null;
        }

        return "https://wa.me/{$numero}".($mensaje !== '' ? '?text='.rawurlencode($mensaje) : '');
    }
}
