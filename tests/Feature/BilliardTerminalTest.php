<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Billiards\BilliardTerminal;
use App\Models\Billiards\BilliardSession;
use App\Models\Billiards\BilliardTable;
use App\Models\SaleInvoice;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\MontaUnSalonDeBillar;
use Tests\TestCase;

/**
 * El terminal del salón: la pantalla con la que se opera el billar.
 *
 * Lo que se prueba aquí es el recorrido completo que hace un mesero en una
 * noche —clic en la mesa, abrirla, cargar una cerveza, cobrar— y las dos
 * reglas de CSS que ya se rompieron en el terminal de parqueadero y se
 * pagaron con defectos en producción.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class BilliardTerminalTest extends TestCase
{
    use MontaUnSalonDeBillar;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->montaUnSalonDeBillar($this->limpiar);

        $this->actingAs($this->cajero);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->empresa);

        $this->abreUnTurnoDeCaja($this->limpiar);
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    /** El mapa del salón carga y muestra las mesas. */
    public function test_el_mapa_del_salon_carga(): void
    {
        Livewire::test(BilliardTerminal::class)
            ->assertOk()
            ->assertSee('Mesa');
    }

    /** Clic en una mesa libre abre el formulario de apertura. */
    public function test_clic_en_una_mesa_libre_ofrece_abrirla(): void
    {
        Livewire::test(BilliardTerminal::class)
            ->call('selectTable', $this->mesa->id)
            ->assertSet('mode', 'open');
    }

    /** Clic en una mesa ocupada abre su cuenta, no una nueva. */
    public function test_clic_en_una_mesa_ocupada_abre_su_cuenta(): void
    {
        $componente = Livewire::test(BilliardTerminal::class)
            ->call('selectTable', $this->mesa->id)
            ->call('abrirMesa')
            ->assertSet('mode', 'account');

        $componente->call('closeModal')
            ->call('selectTable', $this->mesa->id)
            ->assertSet('mode', 'account');

        $this->assertSame(1, BilliardSession::query()
            ->where('table_id', $this->mesa->id)->count(),
            'No puede nacer una segunda cuenta para la misma mesa.');
    }

    /**
     * El recorrido entero: abrir, cargar una cerveza y cobrar.
     *
     * Es la prueba que de verdad importa, porque atraviesa el motor de
     * tarifas, la cuenta, el numerador DIAN, el posteo contable y el pago.
     */
    public function test_abrir_cargar_y_cobrar(): void
    {
        $componente = Livewire::test(BilliardTerminal::class)
            ->call('selectTable', $this->mesa->id)
            ->set('openForm.players', 4)
            ->set('openForm.customer_label', 'Don Jorge')
            ->call('abrirMesa')
            ->call('agregarConsumo', $this->cerveza->id)
            ->call('abrirCobro')
            ->assertSet('mode', 'close')
            ->set('cobroForm.account_id', $this->cuentaCaja)
            ->call('cobrar')
            ->assertSet('mode', 'idle');

        $factura = SaleInvoice::query()
            ->where('company_id', $this->empresa->id)
            ->latest('id')->first();

        $this->assertNotNull($factura, 'No se emitió la factura.');
        $this->assertSame(SaleInvoice::ORIGIN_BILLIARDS, $factura->origin);
        $this->assertSame(BilliardTable::STATUS_FREE, $this->mesa->fresh()->status,
            'Al cobrar, la mesa tiene que quedar libre para los siguientes.');

        unset($componente);
    }

    /** El botón de pausa detiene el reloj desde la pantalla. */
    public function test_se_pausa_y_se_reanuda_desde_la_pantalla(): void
    {
        $componente = Livewire::test(BilliardTerminal::class)
            ->call('selectTable', $this->mesa->id)
            ->call('abrirMesa')
            ->set('pauseReason', 'Se fueron a comer')
            ->call('pausar');

        $sesion = BilliardSession::query()->where('table_id', $this->mesa->id)->first();
        $this->assertTrue($sesion->fresh()->estaPausada());

        $componente->call('reanudar');
        $this->assertFalse($sesion->fresh()->estaPausada());
    }

    /**
     * El cajero no puede regalar plata.
     *
     * La cortesía exige `billiards.adjust`, que el rol CAJERO no trae. Que
     * el botón esté escondido en la vista no es una barrera: el permiso se
     * verifica otra vez en el servidor.
     */
    public function test_el_cajero_no_puede_aplicar_cortesias(): void
    {
        $this->assertFalse($this->cajero->can('billiards.adjust'),
            'El caso depende de que el cajero NO tenga ese permiso.');

        Livewire::test(BilliardTerminal::class)
            ->call('selectTable', $this->mesa->id)
            ->call('abrirMesa')
            ->set('ajusteConcepto', 'Cortesía')
            ->set('ajusteMonto', 5000)
            ->call('agregarAjuste');

        $sesion = BilliardSession::query()->where('table_id', $this->mesa->id)->first();

        $this->assertEqualsWithDelta(0, (float) $sesion->discount_total, 0.01,
            'Sin permiso, la cortesía no se aplica.');
    }

    /** Una mesa marcada ocupada sin cuenta abierta se libera en vez de quedar muerta. */
    public function test_una_mesa_ocupada_sin_cuenta_se_libera(): void
    {
        $this->mesa->update(['status' => BilliardTable::STATUS_OCCUPIED]);

        Livewire::test(BilliardTerminal::class)
            ->call('selectTable', $this->mesa->id)
            ->assertSet('mode', 'idle');

        $this->assertSame(BilliardTable::STATUS_FREE, $this->mesa->fresh()->status);
    }

    // ------------------------------------------------------- el modo oscuro

    /**
     * Ningún campo queda invisible en modo oscuro.
     *
     * La regla del parqueadero decía `input[type=text]`, así que los campos
     * numéricos se quedaban sin fondo ni color y el tema oscuro les ponía
     * texto blanco sobre fondo blanco: el cajero digitaba sin ver.
     *
     * Se mira el CSS y no el render porque el color sale de una hoja de
     * estilos, no del HTML. No reemplaza verlo en pantalla, pero sí atrapa
     * esta regresión concreta.
     */
    public function test_los_campos_no_quedan_invisibles(): void
    {
        $css = $this->hojaDeEstilos();

        $this->assertStringContainsString(
            '.bt-field input, .bt-field textarea, .bt-field select',
            $css,
            'El selector por tipo deja fuera los números: tiene que cubrir todo input.',
        );

        $this->assertStringNotContainsString('.bt-field input[type=text]', $css,
            'Si vuelve el selector por tipo, vuelve el campo invisible.');

        $this->assertStringContainsString('.bt-modal input, .bt-modal textarea, .bt-modal select', $css,
            'La red de seguridad cubre los campos que alguien agregue mañana.');
    }

    /**
     * Los selectores llevan UNA flecha.
     *
     * El navegador dibuja la suya, Filament le pone otra y el parcial
     * compartido una tercera. Encima, la forma corta `background:#fff`
     * resetea también background-image, -repeat, -position y -size: según
     * qué regla ganara el orden, la flecha desaparecía o salía repetida
     * sobre el texto. Así salieron las tres flechas apiladas del parqueadero.
     */
    public function test_los_selectores_llevan_una_sola_flecha(): void
    {
        $css = $this->hojaDeEstilos();

        $this->assertStringContainsString('.bt-overlay .bt-modal select', $css,
            'La regla tiene que ganar por especificidad, no por ir después del parcial.');

        $this->assertStringContainsString('appearance:none !important', $css,
            'Sin apagar la nativa, el navegador dibuja su propia flecha encima.');

        // Solo las reglas que apuntan a campos: un `background` en el div
        // del modal es legítimo —no tiene flecha que borrar—.
        preg_match_all('/([^{}]*\b(?:input|select|textarea)\b[^{}]*)\{([^}]*)\}/i', $css, $reglas, PREG_SET_ORDER);

        $this->assertNotEmpty($reglas, 'Si el CSS se movió de archivo, este guarda dejó de vigilar nada.');

        foreach ($reglas as [$todo, $selector, $cuerpo]) {
            $this->assertDoesNotMatchRegularExpression(
                '/(^|[;\s])background:\s*(#|url|rgb)/i',
                $cuerpo,
                'La forma corta `background` borra la imagen de la flecha. '
                .'Usa background-color en: '.trim($selector),
            );
        }
    }

    private function hojaDeEstilos(): string
    {
        return file_get_contents(
            resource_path('views/filament/app/pages/billiards/partials/estilos.blade.php')
        );
    }
}
