<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La flecha de los <select> en las pantallas operativas.
 *
 * Filament —vía el plugin de formularios de Tailwind— ya le dibuja una flecha
 * a cada `select`, y el navegador dibuja la suya encima. Quedaban **dos
 * flechas sobre el texto**, tapando lo que el usuario había elegido: en el
 * modal de cobro del POS no se leía el método de pago.
 *
 * El primer intento de arreglarlo falló por dos razones que esta prueba fija:
 *
 *  1. **Se aplicó solo a `.pos-select`**, y de los cinco `select` del POS solo
 *     uno usa esa clase. Los demás —motivo del movimiento de caja, tipo de
 *     documento del cliente nuevo— siguieron igual.
 *  2. **No le ganaba en especificidad al CSS de Filament**, que se carga
 *     después, así que en el único select corregido quedaron las dos flechas.
 *
 * Y el mismo defecto estaba en otras siete pantallas. Por eso el estilo vive
 * en UN parcial: copiarlo ocho veces es la forma conocida de que dentro de un
 * mes haya ocho flechas distintas, y de que la novena pantalla nazca otra vez
 * con dos.
 */
class FlechaDeLosSelectTest extends TestCase
{
    /** Las pantallas operativas: las que el usuario usa todo el día. */
    private const PANTALLAS = [
        'pos-terminal.blade.php',
        'restaurant/pos.blade.php',
        'restaurant/delivery-dispatch.blade.php',
        'restaurant/kitchen-display.blade.php',
        'parking/terminal.blade.php',
        'billiards/terminal.blade.php',
        'hotel/front-desk.blade.php',
        'hotel/calendar.blade.php',
        'order-taking/new-order.blade.php',
    ];

    private const PARCIAL = 'resources/views/filament/app/partials/estilos-select.blade.php';

    /** El parcial existe y hace las tres cosas que resuelven la queja. */
    public function test_el_parcial_apaga_la_flecha_nativa_y_reserva_su_sitio(): void
    {
        $css = file_get_contents(base_path(self::PARCIAL));

        $this->assertStringContainsString('appearance: none !important', $css,
            'Sin esto el navegador dibuja su propia flecha encima de la nuestra.');

        $this->assertStringContainsString('padding-right', $css,
            'Es lo que de verdad resuelve la queja: sin sitio reservado, el texto '
            .'queda debajo de la flecha.');

        $this->assertStringContainsString('!important', $css,
            'Compite con el CSS de Filament, que se carga después.');
    }

    /**
     * Toda pantalla con un `select` incluye el parcial.
     *
     * Es la que impide la recaída: una pantalla nueva con un desplegable y sin
     * el include vuelve a tener las dos flechas, y nadie lo nota hasta que un
     * cajero se queja.
     */
    public function test_toda_pantalla_operativa_incluye_el_parcial(): void
    {
        $sinParcial = [];

        foreach (self::PANTALLAS as $pantalla) {
            $ruta = base_path('resources/views/filament/app/pages/'.$pantalla);

            if (! file_exists($ruta)) {
                $this->fail("No existe la pantalla {$pantalla}.");
            }

            $fuente = file_get_contents($ruta);

            if (! str_contains($fuente, 'estilos-select')) {
                $sinParcial[] = $pantalla;
            }
        }

        $this->assertSame([], $sinParcial,
            'Estas pantallas tienen desplegables pero no incluyen el parcial de estilos, '
            .'así que les salen dos flechas encima del texto: '.implode(', ', $sinParcial));
    }

    /**
     * El estilo está escrito una sola vez.
     *
     * Busca una REGLA —el selector seguido de su llave—, no la mención del
     * selector. La primera versión hacía `str_contains('.fi-page select')` y
     * marcaba como copia un comentario del terminal de parqueadero que
     * explicaba por qué su regla necesita dos clases para ganarle a la del
     * parcial. Castigar a quien deja escrito el porqué es exactamente al
     * revés de lo que esta prueba quiere conseguir.
     */
    public function test_el_estilo_no_esta_copiado_en_las_pantallas(): void
    {
        $copias = [];

        foreach (self::PANTALLAS as $pantalla) {
            $fuente = file_get_contents(base_path('resources/views/filament/app/pages/'.$pantalla));

            if (preg_match('/\.fi-page\s+select\s*\{/', $fuente)) {
                $copias[] = $pantalla;
            }
        }

        $this->assertSame([], $copias,
            'El estilo está copiado dentro de estas pantallas en vez de salir del parcial: '
            .implode(', ', $copias));
    }

    /**
     * Y el guarda de arriba sigue sirviendo para algo.
     *
     * Una expresión regular mal escrita no falla: deja de encontrar nada, y
     * una prueba que no puede fallar es peor que no tenerla. Esta comprueba
     * que el patrón sí reconoce una copia de verdad.
     */
    public function test_el_guarda_reconoce_una_copia_de_verdad(): void
    {
        $copiaReal = '<style> .fi-page select { appearance: none !important; } </style>';
        $soloUnaMencion = '{{-- empata con `.fi-page select` del parcial --}}';

        $this->assertSame(1, preg_match('/\.fi-page\s+select\s*\{/', $copiaReal),
            'Si el patrón no reconoce una regla copiada, la prueba no vigila nada.');

        $this->assertSame(0, preg_match('/\.fi-page\s+select\s*\{/', $soloUnaMencion),
            'Una mención en un comentario no es una copia.');
    }
}
