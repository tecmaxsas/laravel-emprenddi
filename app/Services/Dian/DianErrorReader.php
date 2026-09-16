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

    /**
     * Aplana los errores de validación del proveedor a una sola línea.
     *
     * El bloque `errors` de apidian no tiene una forma fija. A veces es
     * `{campo: ["mensaje"]}` como cualquier validación de Laravel, y a veces el
     * proveedor mete otro nivel: `{campo: {sub: ["mensaje"]}}`. Los servicios
     * recorrían un solo nivel y hacían `(string) $msg`, así que ese segundo
     * nivel reventaba con «Array to string conversion» — y el usuario perdía el
     * mensaje real, que era justamente lo que necesitaba para arreglar el envío.
     *
     * @param  array<array-key, mixed>  $errores
     */
    public static function resumen(array $errores): string
    {
        $textos = self::textos($errores);

        return $textos === []
            ? 'El proveedor reportó un error sin detalle.'
            : implode(' · ', array_unique($textos));
    }

    /**
     * Un valor cualquiera de la respuesta, como texto.
     *
     * Ningún campo del proveedor tiene tipo garantizado. `StatusCode`,
     * `StatusDescription` y hasta el `cufe` llegan a veces como estructura en
     * lugar de escalar —depende de cómo quedó la conversión de SOAP a JSON— y
     * `(string) $valor` sobre eso mata la petición entera con «Array to string
     * conversion», que es un mensaje que no le sirve a nadie.
     */
    public static function texto(mixed $valor): string
    {
        if (is_string($valor)) {
            return $valor;
        }

        if ($valor === null || is_bool($valor)) {
            return '';
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if (is_array($valor)) {
            return implode(' · ', self::textos($valor));
        }

        return '';
    }

    /** @return list<string> */
    private static function textos(mixed $contenido): array
    {
        if (is_bool($contenido) || is_int($contenido) || is_float($contenido)) {
            return self::esMotivo((string) $contenido) ? [(string) $contenido] : [];
        }

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
