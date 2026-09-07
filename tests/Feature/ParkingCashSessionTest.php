<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Parking\ParkingTerminal;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Support\CompanyRoles;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El turno de caja se abre desde el propio terminal de parqueadero.
 *
 * Antes el botón "Abrir caja" era un enlace al POS retail, que es donde vive
 * ese formulario. Dejó de servir cuando el POS retail empezó a redirigir al
 * terminal en las empresas de parqueadero: el cajero daba clic, iba y volvía,
 * y la página parecía solo recargarse.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ParkingCashSessionTest extends TestCase
{
    private Company $company;

    private User $cajero;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararParqueadero();

        $this->actingAs($this->cajero);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    public function test_el_cajero_abre_su_turno_sin_salir_del_terminal(): void
    {
        $sede = Location::query()->where('company_id', $this->company->id)->firstOrFail();

        Livewire::test(ParkingTerminal::class)
            ->call('openCashModal')
            ->assertSet('openCashModalOpen', true)
            ->set('openingLocationId', $sede->id)
            ->set('openingAmount', 50000)
            ->call('openCashRegister')
            ->assertSet('openCashModalOpen', false);

        $sesion = CashRegisterSession::query()
            ->where('company_id', $this->company->id)
            ->where('cashier_user_id', $this->cajero->id)
            ->first();

        $this->assertNotNull($sesion, 'No se abrió el turno.');
        $this->assertSame(CashRegisterSession::STATUS_OPEN, $sesion->status);
        $this->assertEqualsWithDelta(50000, (float) $sesion->opening_amount, 0.01);
        $this->assertSame($sede->id, $sesion->location_id);
    }

    /** Sin sede no se abre: la caja pertenece a un punto concreto. */
    public function test_no_se_abre_sin_sede(): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openCashModal')
            ->set('openingLocationId', null)
            ->call('openCashRegister');

        $this->assertSame(0, CashRegisterSession::query()
            ->where('company_id', $this->company->id)->count());
    }

    /** Dos turnos abiertos a la vez descuadran el cierre. */
    public function test_no_se_abre_un_segundo_turno(): void
    {
        $sede = Location::query()->where('company_id', $this->company->id)->firstOrFail();

        $pagina = Livewire::test(ParkingTerminal::class)
            ->call('openCashModal')
            ->set('openingLocationId', $sede->id)
            ->set('openingAmount', 10000)
            ->call('openCashRegister');

        $pagina->call('openCashModal')
            ->assertSet('openCashModalOpen', false, 'Abrió el modal teniendo turno.');

        $this->assertSame(1, CashRegisterSession::query()
            ->where('company_id', $this->company->id)->count());
    }

    private function prepararParqueadero(): void
    {
        $this->company = Company::create([
            'nit' => 'ZZ'.random_int(1000000, 9999999),
            'name' => 'Parqueadero ZZ',
            'document_type' => 'nit',
            'organization_type' => 'juridica',
            'regime_type' => 'comun',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'active_modules' => ['parking'],
            'active' => true,
        ]);

        $sede = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Sede ZZ',
            'code' => 'ZZ'.random_int(100, 999),
            'is_main' => true,
            'active' => true,
        ]);

        $this->cajero = User::create([
            'company_id' => $this->company->id,
            'name' => 'Cajero ZZ',
            'email' => 'cajerozz'.random_int(1000, 9999).'@zz.test',
            'password' => Hash::make('zz'),
            'active' => true,
        ]);

        CompanyRoles::provision($this->company);
        CompanyRoles::assign($this->cajero, 'cashier');

        $this->limpiar[] = function () use ($sede) {
            CashRegisterSession::query()->where('company_id', $this->company->id)->delete();

            $roles = Role::query()->where('company_id', $this->company->id)->pluck('id');
            DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->whereIn('id', $roles)->delete();

            DB::table('locations')->where('id', $sede->id)->delete();
            DB::table('users')->where('id', $this->cajero->id)->delete();
            DB::table('companies')->where('id', $this->company->id)->delete();
        };
    }
}
