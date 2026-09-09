<?php

namespace Tests\Feature;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Tests\TestCase;

/**
 * Peticiones de Livewire con estado caducado.
 *
 * El POS y el terminal de parqueadero se dejan abiertos toda la jornada. Cuando
 * alguien vuelve después de horas, la petición llega con un estado que el
 * servidor ya no reconoce —la sesión venció, o se desplegó otra versión— y hasta
 * ahora eso le mostraba al cajero un volcado de PHP a pantalla completa.
 *
 * No es un fallo del programa: es una página caducada, y Livewire ya sabe
 * manejar un 419 (ofrece recargar). Estas pruebas cuidan las dos mitades de esa
 * traducción: que responda 419, y que quede registrado —porque las mismas
 * excepciones aparecen cuando sí hay un error de programación, y taparlas sin
 * rastro cambiaría un error visible por uno invisible.
 */
class StaleLivewireRequestTest extends TestCase
{
    private const TOKEN = 'zz-token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        // El POST tiene que pasar el CSRF o moriria ahi, y la prueba pasaria
        // por el motivo equivocado. Se manda un token valido en la sesion.
        $this->withSession(['_token' => self::TOKEN]);

        // Rutas de mentira que lanzan lo mismo que lanzaría Livewire.
        Route::middleware('web')->post('/zz-prueba/publica', function () {
            throw new PublicPropertyNotFoundException('$', 'app.pages.parking.parking-terminal');
        });

        Route::middleware('web')->post('/zz-prueba/componente', function () {
            throw new ComponentNotFoundException('No existe');
        });

        Route::middleware('web')->post('/zz-prueba/token', function () {
            throw new TokenMismatchException('CSRF vencido');
        });
    }

    /** Una petición de Livewire caducada responde 419, no una traza. */
    public function test_una_peticion_de_livewire_caducada_responde_419(): void
    {
        foreach (['publica', 'componente', 'token'] as $caso) {
            $this->withHeader('X-Livewire', 'true')
                ->post('/zz-prueba/'.$caso, ['_token' => self::TOKEN])
                ->assertStatus(419);
        }
    }

    /**
     * Fuera de Livewire no se toca nada: una excepción en una petición normal
     * tiene que seguir siendo un error, no un «recarga la página».
     */
    public function test_fuera_de_livewire_la_excepcion_sigue_su_curso(): void
    {
        $this->withoutExceptionHandling();

        $this->expectException(PublicPropertyNotFoundException::class);

        $this->post('/zz-prueba/publica', ['_token' => self::TOKEN]);
    }

    /**
     * Queda registrado, con el componente y la propiedad que fallaron.
     *
     * Es la mitad que importa del arreglo: sin registro, un error de
     * programacion real —que lanza la misma excepcion— desapareceria detras de
     * un «recarga la pagina» y nadie se enteraria nunca.
     */
    public function test_queda_registrado_con_el_componente_y_la_propiedad(): void
    {
        Log::spy();

        $this->withHeader('X-Livewire', 'true')->post('/zz-prueba/publica', [
            '_token' => self::TOKEN,
            'components' => [[
                'snapshot' => ['memo' => ['name' => 'app.pages.parking.parking-terminal']],
                'updates' => ['$' => 'algo'],
            ]],
        ])->assertStatus(419);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $mensaje, array $contexto) {
                return str_contains($mensaje, 'estado caducado')
                    && $contexto['componentes'] === ['app.pages.parking.parking-terminal']
                    && $contexto['actualizaciones'] === ['$'];
            })
            ->once();
    }
}
