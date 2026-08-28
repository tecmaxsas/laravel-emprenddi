<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Parking\ParkingTerminal;
use App\Models\Account;
use App\Models\Company;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingRate;
use App\Models\Parking\ParkingSession;
use App\Models\Parking\ParkingSpace;
use App\Models\Parking\VehicleType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El cajero no deberia elegir cuentas contables: la cuenta la decide el metodo
 * de pago. Con el modulo de contabilidad apagado el campo ni siquiera se pinta.
 *
 * Usa la base de desarrollo y restaura lo que toca en tearDown.
 */
class PaymentAccountResolverTest extends TestCase
{
    private Company $company;

    private User $user;

    private array $orig;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::query()->whereNotNull('company_id')->first();
        $this->company = Company::find($this->user->company_id);
        $this->orig = $this->company->active_modules ?? [];
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];
        $this->company->update(['active_modules' => $this->orig]);
        parent::tearDown();
    }

    public function test_resuelve_la_cuenta_sin_intervencion_del_cajero(): void
    {
        foreach (['cash', 'card', 'transfer'] as $metodo) {
            $id = PaymentAccountResolver::forMethod($metodo, $this->company->id);
            $this->assertNotNull($id, "No resolvió cuenta para {$metodo}");

            $cuenta = Account::withoutGlobalScopes()->find($id);
            $this->assertTrue((bool) $cuenta->accepts_movements, 'Debe aceptar movimientos.');
            fwrite(STDERR, sprintf("\n  %-9s -> %s %s", $metodo, $cuenta->code, $cuenta->name));
        }
        fwrite(STDERR, "\n");
    }

    public function test_el_metodo_configurado_manda_sobre_la_heuristica(): void
    {
        $otra = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('accepts_movements', true)->where('code', 'like', '1110%')->first();

        if (! $otra) {
            $this->markTestSkipped('Sin cuenta 1110 en dev.');
        }

        $pm = PaymentMethod::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'zztest'],
            ['name' => 'ZZ Test', 'type' => 'cash', 'active' => true]
        );
        $pm->update(['account_id' => $otra->id]);

        $this->assertSame($otra->id, PaymentAccountResolver::forMethod('zztest', $this->company->id));

        $pm->forceDelete();
    }

    /**
     * La seccion "Datos del cobro" solo se pinta con una sesion activa y total
     * mayor a cero, asi que hay que montar una salida real.
     */
    private function terminalConSesion(): Testable
    {
        app(CurrentCompany::class)->set($this->company->refresh());

        $cid = $this->company->id;

        $lot = ParkingLot::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $cid, 'code' => 'ZZTEST'],
            ['name' => 'ZZ Test', 'total_capacity' => 10, 'active' => true]
        );

        $tipo = VehicleType::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $cid, 'code' => 'ZZCAR'],
            ['name' => 'ZZ Carro', 'sort_order' => 99, 'active' => true]
        );

        $tarifa = ParkingRate::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $cid, 'name' => 'ZZ Tarifa'],
            [
                'parking_lot_id' => $lot->id,
                'vehicle_type_id' => $tipo->id,
                'kind' => ParkingRate::KIND_REGULAR,
                'config' => ['type' => 'flat', 'amount' => 5000],
                'priority' => 1,
                'active' => true,
            ]
        );

        $espacio = ParkingSpace::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $cid, 'code' => 'ZZ-01'],
            ['parking_lot_id' => $lot->id, 'vehicle_type_id' => $tipo->id, 'status' => 'occupied']
        );

        $sesion = ParkingSession::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $cid, 'plate' => 'ZZZ001', 'exit_at' => null],
            [
                'parking_lot_id' => $lot->id,
                'vehicle_type_id' => $tipo->id,
                'parking_space_id' => $espacio->id,
                'rate_id' => $tarifa->id,
                'space_code' => $espacio->code,
                'entry_at' => now()->subHours(2),
                'status' => 'active',
                'created_by_user_id' => $this->user->id,
            ]
        );

        $this->limpiar[] = fn () => ParkingSession::withoutGlobalScopes()->whereKey($sesion->id)->forceDelete();
        $this->limpiar[] = fn () => ParkingSpace::withoutGlobalScopes()->whereKey($espacio->id)->forceDelete();
        $this->limpiar[] = fn () => ParkingRate::withoutGlobalScopes()->whereKey($tarifa->id)->forceDelete();
        $this->limpiar[] = fn () => VehicleType::withoutGlobalScopes()->whereKey($tipo->id)->forceDelete();
        $this->limpiar[] = fn () => ParkingLot::withoutGlobalScopes()->whereKey($lot->id)->forceDelete();

        $t = Livewire::test(ParkingTerminal::class)
            ->call('selectSession', $sesion->id);

        if ($t->instance()->grandTotal <= 0) {
            $this->markTestSkipped('La sesion abierta no genera cobro.');
        }

        return $t;
    }

    public function test_el_terminal_de_parqueadero_oculta_la_cuenta_sin_contabilidad(): void
    {
        $this->company->update(['active_modules' => ['parking']]);
        $this->terminalConSesion()->assertDontSee('Cuenta contable');
    }

    public function test_el_terminal_de_parqueadero_muestra_la_cuenta_con_contabilidad(): void
    {
        $this->company->update(['active_modules' => ['parking', 'accounting']]);
        $this->terminalConSesion()->assertSee('Cuenta contable');
    }
}
