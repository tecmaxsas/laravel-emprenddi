<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Todas las vistas generan PHP válido.
 *
 * Compilar una vista no basta para saber que sirve: el compilador de Blade
 * produce PHP sin quejarse aunque ese PHP no sea válido. Pasó con `@php(...)`,
 * que en una vista se convirtió en `<?php(` sin cerrar el bloque: todo lo que
 * venía detrás quedó dentro del PHP y la página reventaba con un ParseError
 * que no señalaba la línea culpable.
 *
 * Por eso aquí se compila y además se pasa `php -l` al resultado, que es lo
 * único que confirma que la página va a abrir.
 */
class BladeViewsCompileTest extends TestCase
{
    public function test_todas_las_vistas_generan_php_valido(): void
    {
        $rotas = [];

        foreach ($this->vistas() as $vista) {
            $error = $this->errorDeSintaxis($vista);

            if ($error !== null) {
                $rotas[] = str_replace(resource_path('views/'), '', $vista).' → '.$error;
            }
        }

        $this->assertSame([], $rotas, "Vistas que no generan PHP válido:\n".implode("\n", $rotas));
    }

    /** @return list<string> */
    private function vistas(): array
    {
        $directorio = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        $vistas = [];

        foreach ($directorio as $archivo) {
            if ($archivo->isFile() && str_ends_with($archivo->getFilename(), '.blade.php')) {
                $vistas[] = $archivo->getPathname();
            }
        }

        sort($vistas);

        return $vistas;
    }

    private function errorDeSintaxis(string $vista): ?string
    {
        $php = app('blade.compiler')->compileString(file_get_contents($vista));

        $temporal = tempnam(sys_get_temp_dir(), 'blade-lint').'.php';
        file_put_contents($temporal, $php);

        exec('php -l '.escapeshellarg($temporal).' 2>&1', $salida, $codigo);
        @unlink($temporal);

        if ($codigo === 0) {
            return null;
        }

        return trim(explode("\n", implode("\n", $salida))[0]);
    }
}
