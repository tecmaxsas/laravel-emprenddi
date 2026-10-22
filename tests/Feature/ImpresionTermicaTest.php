<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Que lo impreso en térmica se lea.
 *
 * Las comandas, precuentas y tiquetes salían claros y borrosos. En un mismo
 * papel la negrita salía oscura y el resto no, que es la pista: no era el
 * color —ya era negro— sino el **suavizado**.
 *
 * El navegador dibuja los bordes de cada letra con píxeles intermedios para
 * que se vean redondeados en pantalla. A 203 dpi, que es lo normal en estas
 * impresoras, una letra fina es casi toda borde; el cabezal no tiene grises,
 * así que los convierte en puntos sueltos. La negrita sobrevive porque tiene
 * relleno sólido.
 *
 * Lo que se prueba aquí es que las reglas estén y que estén **en un solo
 * sitio**: escritas por formato, se arreglaba uno y los otros tres seguían
 * saliendo claros, que es exactamente lo que había pasado.
 */
class ImpresionTermicaTest extends TestCase
{
    /** Los cuatro formatos que salen por una impresora térmica. */
    private const FORMATOS = [
        'comanda de cocina' => 'restaurant/print-kot.blade.php',
        'precuenta' => 'restaurant/precheck.blade.php',
        'tiquete de venta' => 'pos/print-ticket.blade.php',
        'tiquete de parqueadero' => 'parking/ticket.blade.php',
    ];

    private function partial(): string
    {
        return file_get_contents(resource_path('views/partials/thermal-print.blade.php'));
    }

    /**
     * El suavizado está desactivado.
     *
     * Es la regla que faltaba, y la única que explica por qué la negrita
     * salía bien y el resto no.
     */
    public function test_se_desactiva_el_suavizado_de_fuente(): void
    {
        $this->assertStringContainsString('-webkit-font-smoothing: none', $this->partial(),
            'Sin esto, cada letra fina se imprime con los bordes en gris punteado.');
    }

    /** Y el negro se fuerza, sin que el navegador «optimice». */
    public function test_el_negro_es_puro(): void
    {
        $partial = $this->partial();

        $this->assertStringContainsString('print-color-adjust: exact', $partial);
        $this->assertStringContainsString('color: #000 !important', $partial);
    }

    /** El trazo tiene cuerpo: una fuente fina deja huecos entre punto y punto. */
    public function test_el_trazo_tiene_cuerpo(): void
    {
        $this->assertMatchesRegularExpression('/font-weight:\s*600/', $this->partial());
    }

    /**
     * Las reglas van dentro de `@media print`.
     *
     * Quitar el suavizado en pantalla dejaría la interfaz con los bordes
     * dentados sin ninguna ganancia.
     */
    public function test_solo_aplica_al_imprimir(): void
    {
        $partial = $this->partial();

        $posMedia = strpos($partial, '@media print');
        $posSmoothing = strpos($partial, '-webkit-font-smoothing');

        $this->assertNotFalse($posMedia);
        $this->assertGreaterThan($posMedia, $posSmoothing,
            'Fuera de @media print, esto solo afea la pantalla.');
    }

    /** Los cuatro formatos usan la regla compartida. */
    public function test_los_cuatro_formatos_la_usan(): void
    {
        foreach (self::FORMATOS as $nombre => $ruta) {
            $vista = file_get_contents(resource_path('views/'.$ruta));

            $this->assertStringContainsString("@include('partials.thermal-print')", $vista,
                "El formato de {$nombre} no incluye las reglas de impresión térmica: "
                .'va a salir claro mientras los otros salen bien.');
        }
    }

    /**
     * Y ninguno se escribe su propia copia.
     *
     * Es lo que había pasado: el tiquete de venta tenía las reglas y los
     * otros tres no. Una copia por formato significa que el siguiente arreglo
     * tampoco llegará a todos.
     */
    public function test_ningun_formato_se_escribe_su_propia_copia(): void
    {
        foreach (self::FORMATOS as $nombre => $ruta) {
            $vista = file_get_contents(resource_path('views/'.$ruta));

            $this->assertStringNotContainsString('-webkit-font-smoothing', $vista,
                "El formato de {$nombre} repite las reglas térmicas en vez de incluirlas.");

            $this->assertStringNotContainsString('print-color-adjust', $vista,
                "El formato de {$nombre} repite las reglas térmicas en vez de incluirlas.");
        }
    }
}
