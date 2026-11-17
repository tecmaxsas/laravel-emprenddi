<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Billiards\BilliardHallResource;
use App\Filament\App\Resources\Billiards\BilliardRateResource;
use App\Filament\App\Resources\Billiards\BilliardTableResource;
use App\Filament\App\Resources\Billiards\BilliardTableTypeResource;
use App\Models\Billiards\BilliardRate;
use App\Services\Billiards\BilliardRateEngine;
use App\Support\CompanyRoles;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\MontaUnSalonDeBillar;
use Tests\TestCase;

/**
 * Las pantallas de configuración del módulo.
 *
 * Un esquema de formulario de Filament mal escrito no falla al desplegar:
 * falla cuando el administrador abre la pantalla. Montar cada una aquí es
 * lo único que lo atrapa antes.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class BilliardResourcesTest extends TestCase
{
    use MontaUnSalonDeBillar;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->montaUnSalonDeBillar($this->limpiar);

        // La configuración la hace el administrador, no el cajero.
        CompanyRoles::assign($this->cajero, 'admin');

        $this->actingAs($this->cajero->fresh());
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->empresa);
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

    public function test_el_listado_de_salas_abre(): void
    {
        Livewire::test(BilliardHallResource\Pages\ListBilliardHalls::class)->assertOk();
    }

    public function test_el_formulario_de_sala_abre(): void
    {
        Livewire::test(BilliardHallResource\Pages\EditBilliardHall::class, ['record' => $this->sala->id])
            ->assertOk()
            ->assertFormSet(['name' => 'Salón ZZ']);
    }

    public function test_el_listado_de_tipos_de_mesa_abre(): void
    {
        Livewire::test(BilliardTableTypeResource\Pages\ListBilliardTableTypes::class)->assertOk();
    }

    public function test_el_formulario_de_mesa_abre(): void
    {
        Livewire::test(BilliardTableResource\Pages\EditBilliardTable::class, ['record' => $this->mesa->id])
            ->assertOk()
            ->assertFormSet(['code' => '1']);
    }

    public function test_el_listado_de_mesas_abre(): void
    {
        Livewire::test(BilliardTableResource\Pages\ListBilliardTables::class)->assertOk();
    }

    public function test_el_listado_de_tarifas_abre(): void
    {
        Livewire::test(BilliardRateResource\Pages\ListBilliardRates::class)->assertOk();
    }

    /**
     * El formulario de la tarifa es el que más tiene que ganar: el JSON de
     * `config` se edita con campos anidados y un repeater de franjas, y las
     * tres secciones escriben sobre la misma clave.
     *
     * Se escribe campo por campo con `set()` y no con `fillForm()` porque es
     * lo que de verdad hace el usuario —cada campo escribe su propia ruta— y
     * porque `fillForm()` con un arreglo anidado no llega a los componentes
     * de una sección con `statePath`: la prueba pasaba sin probar nada.
     */
    public function test_la_tarifa_se_edita_con_sus_franjas(): void
    {
        Livewire::test(BilliardRateResource\Pages\EditBilliardRate::class, ['record' => $this->tarifa->id])
            ->assertOk()
            ->set('data.config.amount', 12000)
            ->set('data.config.rounding_unit_min', 60)
            ->set('data.config.schedules', [
                'nueva' => [
                    'label' => 'Happy hour',
                    'from' => '14:00',
                    'to' => '18:00',
                    'amount' => 6000,
                    'days' => [1, 2],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $guardada = BilliardRate::find($this->tarifa->id);

        $this->assertSame(12000, (int) $guardada->config['amount']);
        $this->assertSame(60, (int) $guardada->config['rounding_unit_min']);

        // Las tres secciones del formulario escriben sobre `config`. Si una
        // pisara a las otras —que es lo que pasa cuando dos contenedores
        // comparten `statePath`— aquí faltaría la mitad del JSON.
        $this->assertCount(1, $guardada->config['schedules'],
            'La franja tiene que sobrevivir junto al resto de la configuración.');
        $this->assertSame('14:00', $guardada->config['schedules'][0]['from']);
        $this->assertSame('per_hour', $guardada->config['type'],
            'La sección de franjas no puede borrar lo que escribió la de arriba.');
    }

    /**
     * Y lo que se guarda es lo que el motor después cobra.
     *
     * Es el eslabón que nadie prueba: el formulario puede guardar un JSON
     * perfectamente válido que el motor no entienda, y eso solo se descubre
     * cobrándole mal a un cliente.
     */
    public function test_lo_que_guarda_el_formulario_es_lo_que_el_motor_cobra(): void
    {
        Livewire::test(BilliardRateResource\Pages\EditBilliardRate::class, ['record' => $this->tarifa->id])
            ->set('data.config.amount', 12000)
            ->set('data.config.schedules', [
                'nueva' => ['label' => 'Happy hour', 'from' => '14:00', 'to' => '18:00', 'amount' => 6000, 'days' => []],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $cobro = app(BilliardRateEngine::class)->calculate(
            BilliardRate::find($this->tarifa->id),
            [[Carbon::parse('2026-11-17 17:00'), Carbon::parse('2026-11-17 19:00')]],
        );

        $this->assertEqualsWithDelta(18000, $cobro['amount'], 0.01,
            'Una hora de happy hour a $6.000 más una hora normal a $12.000.');
    }
}
