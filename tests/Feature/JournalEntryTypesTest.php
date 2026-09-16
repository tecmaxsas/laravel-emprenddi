<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La lista de tipos de asiento vive en dos sitios que se desincronizan solos.
 *
 * `journal_entries.type` tiene un CHECK en la base con la lista cerrada de
 * tipos, y `JournalEntry::TYPES` tiene la misma lista en PHP para las etiquetas.
 * Nada obliga a que coincidan. Cuando alguien agrega un tipo nuevo en el código y
 * se olvida del CHECK, no falla al guardar el archivo ni al correr la app: falla
 * en producción, con un «Check violation 23514» crudo, en el momento exacto en
 * que un usuario contabiliza.
 *
 * Ya pasó dos veces: en mayo con `cogs` al facturar productos con inventario, y
 * en septiembre con `credit_note` al contabilizar una nota crédito —que además
 * dejaba oculto el botón de enviarla a la DIAN, porque solo aparece sobre notas
 * contabilizadas—.
 *
 * Estas pruebas son el pegamento que faltaba.
 */
class JournalEntryTypesTest extends TestCase
{
    /** Lo que acepta la base y lo que conoce el modelo tienen que ser lo mismo. */
    public function test_el_check_de_la_base_y_el_modelo_dicen_lo_mismo(): void
    {
        $enLaBase = $this->tiposDelCheck();
        $enElModelo = array_keys(JournalEntry::TYPES);

        sort($enLaBase);
        sort($enElModelo);

        $this->assertSame($enElModelo, $enLaBase,
            "La lista de tipos de la base y la de JournalEntry::TYPES se separaron.\n"
            .'Solo en el modelo: '.implode(', ', array_diff($enElModelo, $enLaBase))."\n"
            .'Solo en la base:   '.implode(', ', array_diff($enLaBase, $enElModelo))."\n"
            .'Agrega una migración que reconstruya journal_entries_type_check.');
    }

    /**
     * Ningún servicio puede escribir un tipo que la base vaya a rechazar.
     *
     * Esta es la que de verdad habría atajado el fallo: mira el código, no la
     * base, y encuentra el tipo nuevo antes de que alguien lo contabilice.
     */
    public function test_ningun_servicio_escribe_un_tipo_que_la_base_rechaza(): void
    {
        $permitidos = $this->tiposDelCheck();
        $huerfanos = [];
        $revisados = 0;

        foreach ($this->archivosPhpDe(app_path()) as $ruta) {
            $contenido = file_get_contents($ruta);

            // Hay que mirar dentro de cada `JournalEntry::create([...])`, no el
            // archivo entero: varios motores crean el asiento y el movimiento de
            // inventario en el mismo método, y el movimiento tiene su propio
            // `'type'` con otra lista de valores completamente distinta.
            preg_match_all(
                '/JournalEntry::(?:withoutGlobalScopes\(\)->)?create\(\[(.*?)\n\s*\]\)/s',
                $contenido,
                $bloques,
            );

            foreach ($bloques[1] as $bloque) {
                // Los tipos que salen de una variable o un ternario no se pueden
                // leer así; los cubre la prueba de arriba más los tests de cada
                // motor. Aquí van los literales, que son la mayoría.
                if (! preg_match("/'type'\s*=>\s*'([a-z_]+)'/", $bloque, $coincidencia)) {
                    continue;
                }

                $revisados++;

                if (! in_array($coincidencia[1], $permitidos, true)) {
                    $huerfanos[] = str_replace(base_path().'/', '', $ruta)." → '{$coincidencia[1]}'";
                }
            }
        }

        // Sin esto la prueba pasaría sola el día que el regex deje de encontrar
        // bloques —por un cambio de formato, por ejemplo— y nadie se enteraría.
        $this->assertGreaterThanOrEqual(8, $revisados,
            "Solo se encontraron {$revisados} asientos con tipo literal. El patrón "
            .'dejó de reconocer los bloques y la prueba ya no está revisando nada.');

        $this->assertSame([], array_unique($huerfanos),
            "Estos archivos escriben un tipo de asiento que el CHECK de la base rechaza:\n"
            .implode("\n", array_unique($huerfanos)));
    }

    /** Los tipos que agregó este arreglo se pueden guardar de verdad. */
    public function test_los_tipos_de_las_notas_se_pueden_guardar(): void
    {
        foreach (['credit_note', 'debit_note', 'general'] as $tipo) {
            $this->assertContains($tipo, $this->tiposDelCheck(),
                "Contabilizar con type='{$tipo}' volvería a fallar con Check violation.");
        }
    }

    /** @return list<string> */
    private function tiposDelCheck(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El CHECK solo existe en PostgreSQL.');
        }

        $definicion = DB::selectOne("
            select pg_get_constraintdef(oid) as def
            from pg_constraint
            where conname = 'journal_entries_type_check'
        ")?->def;

        $this->assertNotNull($definicion,
            'No existe el CHECK journal_entries_type_check: cualquier tipo pasaría.');

        preg_match_all("/'([a-z_]+)'::/", $definicion, $coincidencias);

        return array_values(array_unique($coincidencias[1]));
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
