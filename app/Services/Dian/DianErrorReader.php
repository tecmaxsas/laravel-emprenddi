<?php

namespace App\Services\Dian;

/**
 * Lee las reglas de validacion que la DIAN reporta como incumplidas.
 *
 * Suena trivial y no lo es. La DIAN responde SOAP y apidian lo convierte a
 * JSON, asi que un elemento vacio no llega como null sino como su
 * representacion XML:
 *
 *     "ErrorMessageList": { "_attributes": { "nil": "true" } }
 *
 * Recorrer eso en busca de textos devuelve la cadena "true", que no es ningun
 * error. Con eso marcabamos como fallidos documentos que la DIAN habia
 * aceptado, y el usuario veia "Nómina 1 → true" sin nada que corregir.
 *
 * Ademas el contenido real cambia de forma segun la operacion: a veces es un
 * string suelto, a veces una lista bajo `string`, y el nombre del bloque es
 * ErrorMessage o ErrorMessageList segun si la respuesta fue sincrona o
 * asincrona. Por eso vive en un solo sitio y no copiado en cada servicio.
 */
class DianErrorReader
{
    /** Claves de la serializacion XML, no contenido. */
    private const CLAVES_TECNICAS = ['_attributes', '_declaration', '_namespace', 'nil'];

    /** Valores que solo aparecen como marca de "vacio", nunca como motivo. */
    private const VALORES_VACIOS = ['true', 'false', 'nil', 'null', ''];

    /**
     * @param  array<string, mixed>|null  $bloque  Un *Result de la respuesta.
     * @return list<string>
     */
    public static function reglas(?array $bloque): array
    {
        if (! $bloque) {
            return [];
        }

        $reglas = [];

        foreach (['ErrorMessageList', 'ErrorMessage'] as $clave) {
            $reglas = array_merge($reglas, self::textos($bloque[$clave] ?? null));
        }

        return array_values(array_unique($reglas));
    }

    /** @return list<string> */
    private static function textos(mixed $contenido): array
    {
        if (is_string($contenido)) {
            return self::esMotivo($contenido) ? [trim($contenido)] : [];
        }

        if (! is_array($contenido)) {
            return [];
        }

        $textos = [];

        foreach ($contenido as $clave => $valor) {
            if (is_string($clave) && (in_array($clave, self::CLAVES_TECNICAS, true) || str_starts_with($clave, '@'))) {
                continue;
            }

            $textos = array_merge($textos, self::textos($valor));
        }

        return $textos;
    }

    private static function esMotivo(string $valor): bool
    {
        return ! in_array(strtolower(trim($valor)), self::VALORES_VACIOS, true);
    }
}
