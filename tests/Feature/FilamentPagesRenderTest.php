<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Que cada pantalla del panel abra.
 *
 * Esta prueba nace de un error que llegó a producción con 477 pruebas en verde:
 * a una pantalla le faltaba el `use` de una clase que acababa de empezar a usar.
 * No es un error de sintaxis —`php -l` no lo ve—, no es un error de lógica
 * —ninguna prueba de negocio lo toca—, y la revisión visual no lo agarra porque
 * el archivo se ve perfectamente bien. Solo aparece cuando alguien abre esa
 * pantalla, y para entonces ya está publicado.
 *
 * Lo que mide es lo mínimo y es justo lo que faltaba: **que la clase se pueda
 * cargar y la página se pueda dibujar**. No comprueba nada del contenido; de eso
 * se encargan las pruebas de cada funcionalidad.
 *
 * Una pantalla que necesite estado previo para dibujarse —un registro
 * seleccionado, un módulo encendido— se salta sola al no ser accesible. Eso es
 * deliberado: es preferible cubrir el 80% sin mantenimiento que cubrir todo a
 * cambio de una prueba que hay que ajustar cada semana.
 */
class FilamentPagesRenderTest extends TestCase
{
    /**
     * Pantallas que no se pueden dibujar sin más contexto del que esta prueba
     * puede dar. Cada una dice por qué, para que la lista no crezca sola.
     */
    private const OMITIDAS = [
        // Terminal de venta: monta el carrito y la caja; tiene sus propias
        // pruebas (PosNegativeStockTest, PosGlobalDiscountTest).
        PosTerminal::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $company = Company::findOrFail($user->company_id);

        $this->actingAs($user);
        app(CurrentCompany::class)->set($company);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    /**
     * Abre los modales de la pantalla.
     *
     * Dibujar la página no basta y ahí estuvo mi error: el formulario de una
     * acción se construye cuando se abre el modal, no antes. La primera versión
     * de esta prueba pasaba con el fallo presente —quitando el `use` a propósito
     * seguía en verde—, que es exactamente la clase de prueba que no sirve.
     *
     * Solo se reportan los `Error` de PHP —clase no encontrada, método que no
     * existe, tipo incompatible—. Una excepción de negocio («elige un cliente
     * primero») es correcta y esperable al abrir un modal sin contexto.
     *
     * @return list<string>
     */
    private function revisarAcciones(string $pagina, $componente): array
    {
        $rotas = [];

        foreach ($this->accionesDe($pagina) as $nombre) {
            try {
                $componente->mountAction($nombre);
            } catch (\Error $e) {
                $rotas[] = class_basename($pagina)." → {$nombre}(): ".$e->getMessage();
            } catch (\Throwable) {
                // Negocio: la acción existe y se construyó, simplemente le falta
                // contexto. No es lo que esta prueba busca.
            }
        }

        return $rotas;
    }

    /**
     * Los modales que declara esta pantalla.
     *
     * Con `get_class_methods()` se colaban los de Filament —`mountAction`,
     * `cacheAction`, `redirectAction`— y la prueba reportaba como rotas cosas
     * del framework. Se filtran por tres cosas a la vez: escritos en el archivo
     * de la propia pantalla, sin argumentos obligatorios, y devolviendo una
     * Action.
     *
     * @return list<string>
     */
    private function accionesDe(string $pagina): array
    {
        $nombres = [];

        foreach ((new \ReflectionClass($pagina))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
            // Por archivo y no por clase declarante: los metodos de un trait
            // —y Filament mete varios con `InteractsWithActions`— reportan como
            // declarante la clase que lo usa, asi que ese filtro no los excluye.
            if ($metodo->getFileName() !== (new \ReflectionClass($pagina))->getFileName()) {
                continue;
            }

            if (! str_ends_with($metodo->getName(), 'Action') || $metodo->getName() === 'Action') {
                continue;
            }

            if ($metodo->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $tipo = $metodo->getReturnType();

            if (! $tipo instanceof \ReflectionNamedType
                || ! is_a($tipo->getName(), Action::class, true)) {
                continue;
            }

            $nombres[] = substr($metodo->getName(), 0, -strlen('Action'));
        }

        return $nombres;
    }

    /** Ninguna pantalla del panel puede reventar al abrirse. */
    public function test_todas_las_pantallas_abren(): void
    {
        $rotas = [];
        $probadas = 0;

        foreach (Filament::getPanel('app')->getPages() as $pagina) {
            if (in_array($pagina, self::OMITIDAS, true)) {
                continue;
            }

            // Una pantalla que el usuario no puede ver no se puede dibujar, y no
            // tiene sentido exigirlo.
            if (method_exists($pagina, 'canAccess') && ! $pagina::canAccess()) {
                continue;
            }

            $probadas++;

            try {
                $componente = Livewire::test($pagina)->assertOk();
            } catch (\Throwable $e) {
                // El mensaje completo importa: «Class X not found» dice de una
                // qué falta, y es justo el caso que motivó esta prueba.
                $rotas[] = class_basename($pagina).': '.$e->getMessage();

                continue;
            }

            $rotas = [...$rotas, ...$this->revisarAcciones($pagina, $componente)];
        }

        // Sin esto la prueba pasaría sola el día que el descubrimiento de
        // páginas deje de funcionar, y nadie se enteraría.
        $this->assertGreaterThanOrEqual(10, $probadas,
            "Solo se probaron {$probadas} pantallas. El panel tiene muchas más: "
            .'el descubrimiento dejó de funcionar y esta prueba ya no revisa nada.');

        $this->assertSame([], $rotas,
            "Estas pantallas no abren:\n".implode("\n", $rotas));
    }
}
