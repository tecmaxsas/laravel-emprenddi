<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raíz no sirve contenido: redirige al panel.
     *
     * Venía del esqueleto de Laravel esperando un 200, que la aplicación dejó
     * de dar cuando `/` pasó a ser un RedirectController. Afirmaba algo que ya
     * no era cierto.
     */
    public function test_la_raiz_redirige(): void
    {
        $this->get('/')->assertRedirect();
    }
}
