<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ninguna clase se usa sin su `use`.
 *
 * Esta prueba nace de un error que llegó a producción con 477 pruebas en verde.
 * Una pantalla empezó a usar una clase nueva y se quedó sin el `use`: PHP
 * entonces la busca en el namespace del archivo, no la encuentra, y revienta con
 * «Class ... not found» en el momento en que alguien abre esa pantalla.
 *
 * Es un error que se escapa de todas las redes habituales:
 *
 *   - `php -l` no lo ve: sintácticamente el archivo es correcto.
 *   - Las pruebas de negocio no lo ven: nadie ejercita esa línea.
 *   - Dibujar la pantalla tampoco basta —lo intenté—: las opciones de un
 *     desplegable dentro de un modal se evalúan al abrirlo, no antes.
 *   - Leer el diff no lo ve: el archivo se ve perfectamente bien.
 *
 * Así que se revisa el texto, que es donde el error vive. Es lo que haría un
 * analizador estático; instalar uno en un proyecto de este tamaño traería miles
 * de avisos preexistentes y un archivo de excepciones que nadie mantiene.
 */
class UnresolvedClassReferencesTest extends TestCase
{
    /**
     * Palabras que se ven como una clase y no lo son.
     */
    private const NO_SON_CLASES = [
        'self', 'static', 'parent', 'class', 'true', 'false', 'null',
        'int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'void',
    ];

    /** Ninguna clase referenciada puede quedar sin resolver. */
    public function test_ninguna_clase_se_usa_sin_su_use(): void
    {
        $huerfanas = [];
        $revisados = 0;

        foreach ($this->archivosPhpDe(app_path()) as $ruta) {
            $revisados++;

            foreach ($this->referenciasSinResolver($ruta) as $clase) {
                $huerfanas[] = str_replace(base_path().'/', '', $ruta)." → {$clase}";
            }
        }

        // Sin esto la prueba pasaría sola el día que el recorrido deje de
        // encontrar archivos, y nadie se enteraría.
        $this->assertGreaterThan(100, $revisados,
            "Solo se revisaron {$revisados} archivos: el recorrido dejó de funcionar.");

        $this->assertSame([], array_unique($huerfanas),
            "Estas clases se usan sin importarlas y revientan al ejecutarse:\n"
            .implode("\n", array_unique($huerfanas)));
    }

    /**
     * Las clases que el archivo nombra y PHP no podría encontrar.
     *
     * Se usa el tokenizador de PHP y no expresiones regulares. Lo intenté con
     * regex y me mordió: quitar los comentarios primero rompe cualquier cadena
     * que contenga `//` —una URL, sin ir más lejos—, y el limpiador de cadenas
     * se termina comiendo medio archivo. El resultado era una prueba que no
     * encontraba nada y parecía funcionar.
     *
     * @return list<string>
     */
    private function referenciasSinResolver(string $ruta): array
    {
        $tokens = token_get_all(file_get_contents($ruta));

        $namespace = $this->namespaceDe($tokens);

        if (! $namespace) {
            return [];
        }

        $importadas = $this->importesDe($tokens);
        $sinResolver = [];

        foreach ($this->candidatas($tokens) as $nombre) {
            if (in_array(strtolower($nombre), self::NO_SON_CLASES, true)) {
                continue;
            }

            if (isset($importadas[$nombre])) {
                continue;
            }

            // Sin `use`, PHP la busca en el namespace del propio archivo.
            if ($this->existe($namespace.'\\'.$nombre)) {
                continue;
            }

            $sinResolver[] = $nombre;
        }

        return $sinResolver;
    }

    /**
     * Los nombres cortos que el archivo usa como clase.
     *
     * Solo `Clase::algo` y `new Clase`: un nombre totalmente calificado
     * (`\App\Algo::`) llega como un token distinto y PHP lo resuelve solo.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return list<string>
     */
    private function candidatas(array $tokens): array
    {
        $nombres = [];
        $anterior = null;

        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                $siguiente = $this->siguienteReal($tokens, $i);

                // Clase::
                if (is_array($siguiente) && $siguiente[0] === T_DOUBLE_COLON) {
                    $nombres[] = $token[1];
                }

                // new Clase
                if (is_array($anterior) && $anterior[0] === T_NEW) {
                    $nombres[] = $token[1];
                }
            }

            if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $anterior = $token;
            }
        }

        return array_values(array_unique($nombres));
    }

    /** @param  list<array{0:int,1:string,2:int}|string>  $tokens */
    private function siguienteReal(array $tokens, int $desde): array|string|null
    {
        $total = count($tokens);

        for ($i = $desde + 1; $i < $total; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /** @param  list<array{0:int,1:string,2:int}|string>  $tokens */
    private function namespaceDe(array $tokens): ?string
    {
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $siguiente = $this->siguienteReal($tokens, $i);

                if (is_array($siguiente)) {
                    return trim($siguiente[1]);
                }
            }
        }

        return null;
    }

    /**
     * Los alias que el archivo importa, incluidos los de un `use A\{B, C}`.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return array<string, string>
     */
    private function importesDe(array $tokens): array
    {
        $importadas = [];
        $total = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }

            // Se acumula hasta el `;` o el `{` de cierre: dentro puede haber
            // varios nombres, alias con `as`, y grupos entre llaves.
            $trozo = '';

            for ($j = $i + 1; $j < $total; $j++) {
                $t = $tokens[$j];

                if ($t === ';' || $t === '(') {
                    break;
                }

                $trozo .= is_array($t) ? $t[1] : $t;
            }

            foreach ($this->aliasesDelTrozo($trozo) as $alias) {
                $importadas[$alias] = $alias;
            }
        }

        return $importadas;
    }

    /**
     * @return list<string>
     */
    private function aliasesDelTrozo(string $trozo): array
    {
        $trozo = trim($trozo);

        if ($trozo === '') {
            return [];
        }

        // `A\{B, C as D}` se abre en sus partes.
        if (preg_match('/^([^{]*)\{(.+)\}$/s', $trozo, $partes)) {
            $alias = [];

            foreach (explode(',', $partes[2]) as $parte) {
                $uno = $this->aliasDe(trim($parte));

                if ($uno) {
                    $alias[] = $uno;
                }
            }

            return $alias;
        }

        $alias = [];

        foreach (explode(',', $trozo) as $parte) {
            $uno = $this->aliasDe(trim($parte));

            if ($uno) {
                $alias[] = $uno;
            }
        }

        return $alias;
    }

    private function aliasDe(string $linea): ?string
    {
        if (preg_match('/\s+as\s+([A-Za-z0-9_]+)$/i', $linea, $m)) {
            return $m[1];
        }

        $partes = explode('\\', $linea);
        $ultimo = trim(end($partes));

        return $ultimo !== '' ? $ultimo : null;
    }

    private function existe(string $clase): bool
    {
        return class_exists($clase)
            || interface_exists($clase)
            || trait_exists($clase)
            || enum_exists($clase);
    }

    /** @return list<string> */
    private function archivosPhpDe(string $directorio): array
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS)
        );

        $rutas = [];

        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && str_ends_with($archivo->getFilename(), '.php')) {
                $rutas[] = $archivo->getPathname();
            }
        }

        return $rutas;
    }
}
