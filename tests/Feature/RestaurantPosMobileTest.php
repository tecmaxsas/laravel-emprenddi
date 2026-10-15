<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El POS de restaurante en un teléfono.
 *
 * Los meseros toman las órdenes de pie, con el teléfono en una mano y el
 * pulgar de la otra. Las quejas eran de uso, no de funciones: los controles
 * se erraban y había que pelear con el scroll.
 *
 * Lo que se revisa aquí son medidas, no apariencia: un botón de 24 píxeles no
 * se puede tocar sin fallar, y eso es comprobable. Lo demás —que se vea
 * bien— hay que mirarlo en un teléfono de verdad.
 */
class RestaurantPosMobileTest extends TestCase
{
    private function hoja(): string
    {
        return file_get_contents(
            resource_path('views/filament/app/pages/restaurant/pos.blade.php')
        );
    }

    /**
     * Los botones de cantidad se pueden tocar con el pulgar.
     *
     * Medían 24×24. El mínimo para no fallar es 44px según Apple y 48dp según
     * Android. Son los controles más usados al tomar una orden, y cada toque
     * errado borra o duplica una línea.
     */
    public function test_los_botones_de_cantidad_son_tactiles(): void
    {
        preg_match('/\.rpos-qty-btn\s*\{([^}]*)\}/', $this->hoja(), $m);

        $this->assertNotEmpty($m, 'No se encontró la regla de los botones de cantidad.');

        preg_match('/width:\s*(\d+)px/', $m[1], $w);

        $this->assertGreaterThanOrEqual(40, (int) ($w[1] ?? 0),
            'Con menos de 40px el mesero falla el toque y edita la línea equivocada.');
    }

    /**
     * Y no disparan el zoom por doble toque.
     *
     * Sumar cantidad son toques seguidos en el mismo punto, que es justo lo
     * que el navegador interpreta como doble toque: el POS hacía zoom en
     * lugar de sumar.
     */
    public function test_los_toques_seguidos_no_hacen_zoom(): void
    {
        preg_match('/\.rpos-qty-btn\s*\{([^}]*)\}/', $this->hoja(), $m);

        $this->assertStringContainsString('touch-action:manipulation', $m[1] ?? '');
    }

    /**
     * La fila de un ítem se parte en dos renglones en móvil.
     *
     * Con los botones ya en tamaño táctil, lo fijo de la fila suma unos 270px:
     * en un teléfono de 360px quedaban 26px para el nombre del producto.
     */
    public function test_la_fila_del_item_se_parte_en_movil(): void
    {
        $hoja = $this->hoja();

        preg_match('/\.rpos-item-row\s*\{([^}]*)\}/', $hoja, $m);

        $this->assertStringContainsString('flex-wrap:wrap', $m[1] ?? '',
            'Sin esto el nombre del producto queda aplastado contra los botones.');

        $this->assertStringContainsString('flex-wrap:nowrap', $hoja,
            'En escritorio sigue en una sola línea: ahí sobra ancho.');
    }

    /**
     * La rejilla de productos deja ver más de fila y media.
     *
     * Estaba en 200px: una caja con scroll propio, dentro de una página que
     * también scrollea. El mesero intentaba bajar y movía la lista
     * equivocada.
     */
    public function test_la_rejilla_de_productos_no_es_una_rendija(): void
    {
        preg_match('/\.rpos-products-grid\s*\{([^}]*)\}/', $this->hoja(), $m);

        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('max-height:200px', $m[1],
            '200px muestran fila y media de productos.');
        $this->assertStringContainsString('vh', $m[1],
            'El alto se mide contra la pantalla, que es lo que varía entre teléfonos.');
    }

    /** Los botones por curso no salen de a cuatro en un teléfono. */
    public function test_los_botones_por_curso_van_de_a_dos_en_movil(): void
    {
        $hoja = $this->hoja();

        $this->assertStringContainsString('.rpos-course-grid', $hoja);
        $this->assertStringNotContainsString(
            'grid-template-columns: repeat({{ min($pendingByCourse->count(), 4) }}, 1fr)',
            $hoja,
            'Cuatro columnas fijas en un teléfono son cuatro botones con el texto partido.',
        );
    }
}
