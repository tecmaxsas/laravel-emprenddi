<?php

namespace Tests\Feature;

use App\Filament\App\Resources\ThirdPartyResource\Pages\CreateThirdParty;
use App\Filament\App\Resources\ThirdPartyResource\Pages\EditThirdParty;
use App\Models\Company;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\CustomerCreditGuard;
use App\Services\Sales\CustomerStatement;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Saldo de apertura desde el formulario del tercero.
 *
 * Es lo que el cliente ya debía cuando la empresa empezó a usar Emprenddi. Lo
 * tenía la base y lo usaban tres reportes, pero el único camino para cargarlo
 * era la importación masiva: para un cliente que ya debía había que armar un
 * Excel de una fila. Ahora se escribe donde se crea el cliente.
 *
 * Lo que más cuidan estas pruebas no es que el campo guarde —eso es lo fácil—
 * sino dos cosas:
 *
 *  1. **Que la fecha sea obligatoria cuando hay saldo.** Sin fecha, la cartera
 *     por edades no sabe desde cuándo debe y lo trata como deuda de hoy: un
 *     saldo de hace dos años aparece al día y nadie lo cobra.
 *
 *  2. **Que editar un tercero no le borre el saldo que ya tenía.** El campo es
 *     nuevo en el formulario, y los terceros que ya existen traen saldos
 *     cargados por importación.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ThirdPartyOpeningBalanceTest extends TestCase
{
    private Company $empresa;

    private User $usuario;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->usuario->company_id);

        $this->actingAs($this->usuario);
        app(CurrentCompany::class)->set($this->empresa);
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $paso) {
            try {
                $paso();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    /* ------------------------------------------------------------------ */
    /*  Crear */
    /* ------------------------------------------------------------------ */

    /** Se crea el cliente con su saldo en un solo paso. */
    public function test_se_crea_un_cliente_con_saldo_de_apertura(): void
    {
        $documento = $this->documento();

        $this->llenar(CreateThirdParty::class, $this->datos($documento, [
            'opening_balance' => 340000,
            'opening_balance_date' => '2026-01-15',
        ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $cliente = $this->buscar($documento);

        $this->assertNotNull($cliente, 'No se creó el tercero.');
        $this->assertEqualsWithDelta(340000, (float) $cliente->opening_balance, 0.01);
        $this->assertSame('2026-01-15', $cliente->opening_balance_date->toDateString());
        $this->assertTrue($cliente->tieneSaldoDeApertura());
    }

    /** Y se puede crear sin saldo, que es el caso normal. */
    public function test_se_crea_un_cliente_sin_saldo_y_no_pide_fecha(): void
    {
        $documento = $this->documento();

        $this->llenar(CreateThirdParty::class, $this->datos($documento))
            ->call('create')
            ->assertHasNoFormErrors();

        $cliente = $this->buscar($documento);

        $this->assertNotNull($cliente);
        $this->assertEqualsWithDelta(0, (float) $cliente->opening_balance, 0.01);
        $this->assertNull($cliente->opening_balance_date);
        $this->assertFalse($cliente->tieneSaldoDeApertura());
    }

    /**
     * Con saldo y sin fecha no se guarda.
     *
     * Es la regla que de verdad protege algo: la cartera por edades ubica la
     * deuda por los días transcurridos desde esa fecha, y sin ella la cuenta
     * en el tramo de 0 días. Un saldo viejo pasaría por deuda corriente.
     */
    public function test_un_saldo_sin_fecha_no_se_guarda(): void
    {
        $documento = $this->documento();

        $this->llenar(CreateThirdParty::class, $this->datos($documento, [
            'opening_balance' => 500000,
        ]))
            ->call('create')
            ->assertHasFormErrors(['opening_balance_date']);

        $this->assertNull($this->buscar($documento), 'No debió crearse nada.');
    }

    /** La fecha no puede ser futura: un saldo de apertura ya ocurrió. */
    public function test_la_fecha_no_puede_ser_futura(): void
    {
        $documento = $this->documento();

        $this->llenar(CreateThirdParty::class, $this->datos($documento, [
            'opening_balance' => 100000,
            'opening_balance_date' => now()->addMonth()->toDateString(),
        ]))
            ->call('create')
            ->assertHasFormErrors(['opening_balance_date']);

        $this->assertNull($this->buscar($documento));
    }

    /* ------------------------------------------------------------------ */
    /*  Editar: lo que no se puede perder */
    /* ------------------------------------------------------------------ */

    /**
     * Editar otra cosa no borra el saldo cargado por importación.
     *
     * El campo es nuevo en esta pantalla y los terceros que ya existen traen
     * saldos puestos por el importador. Si al cambiarle el teléfono a un
     * cliente se le perdiera la deuda vieja, nadie lo notaría hasta que el
     * estado de cuenta ya no cuadre con lo que el cliente cree deber.
     */
    public function test_editar_el_telefono_no_borra_el_saldo(): void
    {
        $cliente = $this->clienteConSaldo(340000, '2026-01-15');

        $this->llenar(EditThirdParty::class, ['phone' => '6041234567'], $cliente)
            ->call('save')
            ->assertHasNoFormErrors();

        $fresco = $cliente->fresh();

        $this->assertEqualsWithDelta(340000, (float) $fresco->opening_balance, 0.01,
            'El saldo de apertura se perdió al editar otra cosa.');
        $this->assertSame('2026-01-15', $fresco->opening_balance_date->toDateString());
    }

    /** Corregir el saldo desde la pantalla sí se puede: era lo que faltaba. */
    public function test_se_corrige_el_saldo_desde_la_edicion(): void
    {
        $cliente = $this->clienteConSaldo(340000, '2026-01-15');

        $this->llenar(EditThirdParty::class, [
            'opening_balance' => 120000,
            'opening_balance_date' => '2026-02-01',
        ], $cliente)
            ->call('save')
            ->assertHasNoFormErrors();

        $fresco = $cliente->fresh();

        $this->assertEqualsWithDelta(120000, (float) $fresco->opening_balance, 0.01);
        $this->assertSame('2026-02-01', $fresco->opening_balance_date->toDateString());
    }

    /** Y dejarlo en cero también, para el que se cargó por error. */
    public function test_se_puede_dejar_el_saldo_en_cero(): void
    {
        $cliente = $this->clienteConSaldo(340000, '2026-01-15');

        $this->llenar(EditThirdParty::class, ['opening_balance' => 0], $cliente)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(0, (float) $cliente->fresh()->opening_balance, 0.01);
    }

    /* ------------------------------------------------------------------ */
    /*  Que el saldo signifique lo mismo en todas partes */
    /* ------------------------------------------------------------------ */

    /** Abre el estado de cuenta, con su fecha. */
    public function test_el_saldo_abre_el_estado_de_cuenta(): void
    {
        $cliente = $this->clienteConSaldo(340000, '2026-01-15');

        $estado = app(CustomerStatement::class)->build($cliente);

        $primera = $estado['movements']->first();

        $this->assertSame(CustomerStatement::OPENING, $primera['type']);
        $this->assertSame('2026-01-15', $primera['date']);
        $this->assertEqualsWithDelta(340000, (float) $primera['debit'], 0.01);
        $this->assertEqualsWithDelta(340000, (float) $estado['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(340000, (float) $estado['due'], 0.01,
            'Sin facturas, lo que debe es justo el saldo de apertura.');
    }

    /** Y consume cupo de crédito, que es lo que evita seguir despachando. */
    public function test_el_saldo_consume_cupo(): void
    {
        $cliente = $this->clienteConSaldo(340000, '2026-01-15');

        DB::table('third_parties')->where('id', $cliente->id)->update(['credit_limit' => 1000000]);

        $disponible = app(CustomerCreditGuard::class)->availableCredit($cliente->fresh());

        $this->assertNotNull($disponible, 'Con cupo definido tiene que haber disponible calculado.');
        $this->assertEqualsWithDelta(660000, $disponible, 0.01,
            'El cupo disponible tiene que descontar el saldo de apertura.');
    }

    /**
     * La regla de «hay saldo» es una sola.
     *
     * El importador exigía fecha con `!= 0` y el estado de cuenta pintaba la
     * línea con `> 0.01`: entre los dos había un hueco de un centavo donde el
     * importador obligaba a poner fecha para un saldo que después no se
     * mostraba. Ahora los tres —importador, formulario y estado de cuenta—
     * preguntan lo mismo.
     */
    public function test_la_regla_de_hay_saldo_es_una_sola(): void
    {
        $this->assertFalse(ThirdParty::esSaldoDeApertura(null));
        $this->assertFalse(ThirdParty::esSaldoDeApertura(''));
        $this->assertFalse(ThirdParty::esSaldoDeApertura(0));
        $this->assertFalse(ThirdParty::esSaldoDeApertura(0.001));

        $this->assertTrue(ThirdParty::esSaldoDeApertura(0.5));
        $this->assertTrue(ThirdParty::esSaldoDeApertura('340000'));

        // Un saldo a favor traído del sistema anterior también es un saldo de
        // apertura, y también necesita fecha.
        $this->assertTrue(ThirdParty::esSaldoDeApertura(-50000));
    }

    // --------------------------------------------------------- auxiliares

    /**
     * Monta la página y escribe el estado campo por campo.
     *
     * Con `fillForm()` el estado se quedaba en los valores por defecto y la
     * prueba fallaba por los campos obligatorios, no por lo que estaba
     * probando. `set('data.…')` es lo que usan las demás pruebas de formularios
     * de Filament en este proyecto.
     *
     * @param  class-string  $pagina
     * @param  array<string, mixed>  $datos
     */
    private function llenar(string $pagina, array $datos, ?ThirdParty $registro = null): Testable
    {
        $test = $registro
            ? Livewire::test($pagina, ['record' => $registro->getRouteKey()])
            : Livewire::test($pagina);

        foreach ($datos as $campo => $valor) {
            $test->set('data.'.$campo, $valor);
        }

        return $test;
    }

    /** @return array<string, mixed> */
    private function datos(string $documento, array $extra = []): array
    {
        return array_merge([
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => $documento,
            'name' => 'ZZAPERTURA Cliente '.$documento,
            'is_customer' => true,
        ], $extra);
    }

    private function documento(): string
    {
        return 'ZZAP'.random_int(100000, 999999);
    }

    private function buscar(string $documento): ?ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('document_number', $documento)
            ->first();

        if ($cliente) {
            $this->limpiar[] = fn () => DB::table('third_parties')->where('id', $cliente->id)->delete();
        }

        return $cliente;
    }

    private function clienteConSaldo(float $saldo, string $fecha): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => $this->documento(),
            'name' => 'ZZAPERTURA Con saldo',
            'is_customer' => true,
            'opening_balance' => $saldo,
            'opening_balance_date' => $fecha,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('third_parties')->where('id', $cliente->id)->delete();

        return $cliente;
    }
}
