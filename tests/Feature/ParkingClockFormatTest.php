<?php

namespace Tests\Feature;

use App\Support\ClockFormat;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Las horas del parqueadero, en formato de 12 horas.
 *
 * Mostraban «18:15» y el operario tenía que traducirlo mentalmente cada vez. En
 * Colombia la hora se lee de 12 horas: con «6:15 pm» un cajero no se equivoca,
 * con «18:15» a veces sí — y en un parqueadero la hora de entrada es lo que
 * decide cuánto se cobra.
 *
 * La segunda prueba es la que de verdad protege el cambio: un `H:i` vuelve a
 * colarse con el primer copiar-pegar de otra pantalla, y nadie lo nota hasta
 * que un cliente reclama.
 */
class ParkingClockFormatTest extends TestCase
{
    /** Los cuatro formatos dicen la hora como la lee la gente. */
    public function test_los_formatos_son_de_doce_horas(): void
    {
        $tarde = Carbon::parse('2026-09-15 18:15:42');
        $manana = Carbon::parse('2026-09-15 06:15:42');

        $this->assertSame('6:15 pm', $tarde->format(ClockFormat::TIME));
        $this->assertSame('06:15 pm', $tarde->format(ClockFormat::TIME_PADDED));
        $this->assertSame('15/09/2026 06:15 pm', $tarde->format(ClockFormat::DATETIME));
        $this->assertSame('15/09/2026 06:15:42 pm', $tarde->format(ClockFormat::DATETIME_SECONDS));

        // La mañana y la tarde tienen que distinguirse: es justo lo que el
        // formato militar resolvía y el de 12 horas puede perder si falta el
        // sufijo.
        $this->assertSame('6:15 am', $manana->format(ClockFormat::TIME));
        $this->assertNotSame(
            $manana->format(ClockFormat::DATETIME),
            $tarde->format(ClockFormat::DATETIME),
        );
    }

    /** La medianoche y el mediodía son donde el formato de 12 horas se rompe. */
    public function test_medianoche_y_mediodia_no_se_confunden(): void
    {
        $this->assertSame('12:00 am', Carbon::parse('2026-09-15 00:00')->format(ClockFormat::TIME));
        $this->assertSame('12:00 pm', Carbon::parse('2026-09-15 12:00')->format(ClockFormat::TIME));
    }

    /**
     * Ningún archivo del parqueadero puede volver a imprimir hora militar.
     *
     * Se revisa el texto porque el fallo real no es de lógica: es alguien
     * copiando una línea de otra pantalla. Así lo ve la suite y no el cliente.
     */
    public function test_no_queda_ninguna_hora_militar_en_el_modulo(): void
    {
        $rutas = array_merge(
            $this->archivosDe(app_path('Filament/App/Pages/Parking')),
            $this->archivosDe(app_path('Filament/App/Resources/Parking')),
            $this->archivosDe(app_path('Services/Parking')),
            $this->archivosDe(resource_path('views/filament/app/pages/parking')),
            $this->archivosDe(resource_path('views/parking')),
        );

        $this->assertNotEmpty($rutas, 'No se encontró el módulo de parqueadero.');

        $culpables = [];

        foreach ($rutas as $ruta) {
            $contenido = file_get_contents($ruta);

            // `H` es la hora de 0 a 23; `G` la misma sin cero delante.
            if (preg_match("/format\(['\"][^'\"]*[HG]:i/", $contenido)
                || preg_match("/dateTime\(['\"][^'\"]*[HG]:i/", $contenido)
                || preg_match("/->time\(['\"][^'\"]*[HG]:i/", $contenido)) {
                $culpables[] = str_replace(base_path().'/', '', $ruta);
            }
        }

        $this->assertSame([], $culpables,
            "Estos archivos del parqueadero volvieron a mostrar hora de 24 horas:\n"
            .implode("\n", $culpables));
    }

    /** @return list<string> */
    private function archivosDe(string $directorio): array
    {
        if (! is_dir($directorio)) {
            return [];
        }

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
