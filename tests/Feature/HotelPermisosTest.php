<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Hotel\HotelFrontDesk;
use App\Models\Company;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Location;
use App\Models\User;
use App\Services\Auth\PermissionsCatalog;
use App\Services\Hotel\HotelStayEngine;
use App\Support\CompanyRoles;
use App\Support\CurrentCompany;
use App\Support\PosDestination;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\MontaUnHotel;
use Tests\TestCase;

/**
 * Quién puede dejar de cobrar.
 *
 * Es la lección del «cerrar sin facturar» del restaurante, aplicada antes de
 * que haga falta arreglarla: el recepcionista aloja, reserva, registra
 * huéspedes y carga consumos, pero no tiene con qué dejar de cobrar. Cerrar un
 * folio sin facturar, anular una estadía y rebajar una cuenta son plata que el
 * hotel no recibe, y eso lo autoriza un administrador.
 *
 * Crea su propia empresa porque lo que mide son los permisos del rol, y
 * tocarle los roles a la empresa de desarrollo dejaría a otras pruebas
 * trabajando con permisos distintos.
 */
class HotelPermisosTest extends TestCase
{
    use MontaUnHotel;

    private User $recepcionista;

    private User $administrador;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparaLaEmpresa();

        $this->actingAs($this->recepcionista);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->empresa);
    }

    protected function tearDown(): void
    {
        $this->limpiaLoCreado($this->limpiar);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  El catálogo */
    /* ------------------------------------------------------------------ */

    /** El grupo existe y está completo: si falta un permiso, no hay forma de asignarlo. */
    public function test_el_catalogo_tiene_el_grupo_de_hotel(): void
    {
        $grupos = PermissionsCatalog::groups();

        $this->assertArrayHasKey('Hotel', $grupos);

        foreach ([
            'hotel.use',
            'hotel.manage',
            'hotel.rates.manage',
            'hotel.reservations.manage',
            'hotel.guests.manage',
            'hotel.folio.charge',
            'hotel.folio.adjust',
            'hotel.room.change',
            'hotel.stay.close_without_invoice',
            'hotel.stay.cancel',
            'hotel.housekeeping',
            'hotel.reports',
        ] as $permiso) {
            $this->assertArrayHasKey($permiso, $grupos['Hotel'], "Falta {$permiso} en el catálogo.");
        }
    }

    /**
     * El cajero/recepcionista puede operar, no puede regalar.
     */
    public function test_el_recepcionista_opera_pero_no_deja_de_cobrar(): void
    {
        $delCajero = PermissionsCatalog::defaultForRole('cashier');

        foreach (['hotel.use', 'hotel.reservations.manage', 'hotel.guests.manage',
            'hotel.folio.charge', 'hotel.housekeeping'] as $permiso) {
            $this->assertContains($permiso, $delCajero, "El recepcionista necesita {$permiso}.");
        }

        foreach (['hotel.stay.close_without_invoice', 'hotel.stay.cancel', 'hotel.folio.adjust',
            'hotel.manage', 'hotel.rates.manage'] as $permiso) {
            $this->assertNotContains(
                $permiso,
                $delCajero,
                "El recepcionista NO debería traer {$permiso}: es plata que el hotel deja de recibir.",
            );
        }
    }

    /** El administrador sí los trae todos. */
    public function test_el_administrador_tiene_todo_el_modulo(): void
    {
        $delAdmin = PermissionsCatalog::defaultForRole('admin');

        foreach (array_keys(PermissionsCatalog::groups()['Hotel']) as $permiso) {
            $this->assertContains($permiso, $delAdmin);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Lo que se puede hacer de verdad */
    /* ------------------------------------------------------------------ */

    /** El recepcionista entra a la recepción. */
    public function test_la_recepcion_se_abre_con_hotel_use(): void
    {
        $this->assertTrue($this->recepcionista->can('hotel.use'));
        $this->assertTrue(HotelFrontDesk::canAccess());

        Livewire::test(HotelFrontDesk::class)->assertOk();
    }

    /** Sin el módulo encendido, la recepción no existe para nadie. */
    public function test_sin_el_modulo_la_recepcion_no_existe(): void
    {
        $this->empresa->update(['active_modules' => []]);
        app(CurrentCompany::class)->set($this->empresa->fresh());
        $this->recepcionista->unsetRelation('company');

        $this->assertFalse(
            HotelFrontDesk::canAccess(),
            'El módulo apagado tiene que ocultar el módulo entero.',
        );
    }

    /**
     * El recepcionista NO puede cerrar sin facturar desde la pantalla.
     *
     * La pantalla lo bloquea y el motor también: dos cerraduras para la misma
     * puerta, porque la de la pantalla se puede saltar llamando la acción de
     * Livewire a mano.
     */
    public function test_el_recepcionista_no_cierra_sin_facturar(): void
    {
        $estadia = $this->alojaAlguien();

        Livewire::test(HotelFrontDesk::class)
            ->call('selectRoom', $estadia->room_id)
            ->set('checkoutForm.no_invoice_reason', 'Regalada ZZ')
            ->call('cerrarSinFacturar');

        $this->assertSame(
            Stay::STATUS_OPEN,
            $estadia->fresh()->status,
            'Sin permiso, la estadía tiene que quedarse abierta.',
        );
    }

    /** Tampoco puede anular la estadía. */
    public function test_el_recepcionista_no_anula_la_estadia(): void
    {
        $estadia = $this->alojaAlguien();

        Livewire::test(HotelFrontDesk::class)
            ->call('selectRoom', $estadia->room_id)
            ->set('checkoutForm.no_invoice_reason', 'Me equivoqué ZZ')
            ->call('anularEstadia');

        $this->assertSame(Stay::STATUS_OPEN, $estadia->fresh()->status);
    }

    /** Ni rebajar el folio. */
    public function test_el_recepcionista_no_rebaja_el_folio(): void
    {
        $estadia = $this->alojaAlguien();
        $totalAntes = (float) $estadia->fresh()->total;

        Livewire::test(HotelFrontDesk::class)
            ->call('selectRoom', $estadia->room_id)
            ->call('abrirAjuste')
            ->assertSet('adjustOpen', false, 'El modal del ajuste no debería abrirse sin permiso.')
            ->set('adjustForm.concepto', 'Cortesía ZZ')
            ->set('adjustForm.monto', 50000)
            ->call('aplicarAjuste');

        $this->assertEqualsWithDelta(
            $totalAntes,
            (float) $estadia->fresh()->total,
            0.01,
            'El folio no debería haber bajado.',
        );
    }

    /** El administrador sí puede cerrar sin facturar. */
    public function test_el_administrador_si_cierra_sin_facturar(): void
    {
        $estadia = $this->alojaAlguien();

        $this->actingAs($this->administrador);
        app(CurrentCompany::class)->set($this->empresa);

        Livewire::test(HotelFrontDesk::class)
            ->call('selectRoom', $estadia->room_id)
            ->set('checkoutForm.no_invoice_reason', 'Habitación de cortesía ZZ')
            ->call('cerrarSinFacturar');

        $this->assertSame(Stay::STATUS_CLOSED, $estadia->fresh()->status);
        $this->assertNull($estadia->fresh()->sale_invoice_id, 'Cerrar sin facturar no emite factura.');
    }

    /* ------------------------------------------------------------------ */
    /*  A qué POS lleva el botón */
    /* ------------------------------------------------------------------ */

    /**
     * En una empresa de hotel, el botón POS lleva a la recepción.
     *
     * Es la misma corrección que hubo que hacerle al parqueadero: antes
     * mandaba a todo el mundo al POS de retail sin importar su negocio, y el
     * recepcionista llegaba a una pantalla de venta de mostrador que no tiene
     * nada que ver con alojar a alguien.
     */
    public function test_el_boton_pos_lleva_a_la_recepcion(): void
    {
        $this->assertSame(PosDestination::HOTEL, PosDestination::resolve());
        $this->assertSame(route('filament.app.pages.hotel'), PosDestination::url());
    }

    // --------------------------------------------------------- auxiliares

    private function alojaAlguien(): Stay
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        // El check-in lo hace el motor directamente: lo que se está midiendo
        // es quién puede cerrarlo, no quién puede abrirlo.
        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $habitacion->id,
            'expected_check_out' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame(Room::STATUS_OCCUPIED, $habitacion->fresh()->status);

        return $estadia;
    }

    private function preparaLaEmpresa(): void
    {
        $this->empresa = Company::create([
            'nit' => 'ZH'.random_int(1000000, 9999999),
            'name' => 'Hotel ZZ Permisos',
            'document_type' => 'nit',
            'organization_type' => 'juridica',
            'regime_type' => 'comun',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'active_modules' => ['hotel'],
            'active' => true,
        ]);

        $this->sede = Location::create([
            'company_id' => $this->empresa->id,
            'name' => 'Sede ZZH',
            'code' => 'ZH'.random_int(100, 999),
            'is_main' => true,
            'active' => true,
        ]);

        $this->recepcionista = User::create([
            'company_id' => $this->empresa->id,
            'name' => 'Recepción ZZ',
            'email' => 'recepcionzz'.random_int(1000, 9999).'@zz.test',
            'password' => Hash::make('zz'),
            'active' => true,
        ]);

        $this->administrador = User::create([
            'company_id' => $this->empresa->id,
            'name' => 'Admin ZZH',
            'email' => 'adminzzh'.random_int(1000, 9999).'@zz.test',
            'password' => Hash::make('zz'),
            'active' => true,
        ]);

        CompanyRoles::provision($this->empresa);
        CompanyRoles::assign($this->recepcionista, 'cashier');
        CompanyRoles::assign($this->administrador, 'admin');

        $this->limpiar[] = function () {
            $roles = Role::query()->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->whereIn('id', $roles)->delete();
            DB::table('users')->where('company_id', $this->empresa->id)->delete();
            DB::table('locations')->where('company_id', $this->empresa->id)->delete();
            DB::table('companies')->where('id', $this->empresa->id)->delete();
        };

        $this->hotel = $this->montaUnHotel($this->empresa, $this->sede, $this->limpiar, habitaciones: 1);
    }
}
