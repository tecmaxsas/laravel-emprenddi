<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hotel\Guest;
use App\Models\Hotel\Stay;
use App\Models\Location;
use App\Models\User;
use App\Services\Hotel\HotelFolio;
use App\Services\Hotel\HotelStayEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontaUnHotel;
use Tests\TestCase;

/**
 * Los papeles del hotel: la cuenta y la tarjeta de registro.
 *
 * Lo que se mide no es el diseño, es que abran y que digan lo que tienen que
 * decir. Dos cosas en concreto:
 *
 *  - **La cuenta tiene que avisar que no es una factura.** Se imprime en la
 *    misma impresora que la factura, se parece y lleva los mismos totales; sin
 *    el aviso el huésped se va creyendo que ya la tiene, y en Colombia eso es
 *    un problema con la DIAN.
 *  - **Nadie puede imprimir la cuenta de otra empresa.** Es la cuenta de una
 *    persona con su nombre y su documento: un número de estadía en la URL no
 *    puede ser suficiente.
 */
class HotelImpresosTest extends TestCase
{
    use MontaUnHotel;

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

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->enciendeElModuloHotel($this->empresa, $this->limpiar);
        $this->hotel = $this->montaUnHotel($this->empresa, $this->sede, $this->limpiar, habitaciones: 1);
    }

    protected function tearDown(): void
    {
        $this->limpiaLoCreado($this->limpiar);

        parent::tearDown();
    }

    /** La cuenta abre y muestra el folio con sus renglones. */
    public function test_la_cuenta_se_imprime(): void
    {
        $estadia = $this->aloja();

        app(HotelFolio::class)->agregarConsumo($estadia, [
            'description' => 'Lavandería ZZ',
            'quantity' => 1,
            'unit_price' => 15000,
        ]);

        $this->get(route('hotel.folio.print', $estadia))
            ->assertOk()
            ->assertSee($estadia->fullNumber())
            ->assertSee('Lavandería ZZ')
            ->assertSee('ZZ-101');
    }

    /**
     * Y dice, en grande, que no es una factura de venta.
     *
     * No es configurable a propósito. Es el mismo aviso de la precuenta del
     * restaurante y por la misma razón.
     */
    public function test_la_cuenta_avisa_que_no_es_una_factura(): void
    {
        $estadia = $this->aloja();

        $this->get(route('hotel.folio.print', $estadia))
            ->assertOk()
            ->assertSee('NO es una factura de venta', escape: false);
    }

    /** Nadie imprime la cuenta de otra empresa. */
    public function test_no_se_imprime_la_cuenta_de_otra_empresa(): void
    {
        $estadia = $this->aloja();

        $otro = User::query()
            ->whereNotNull('company_id')
            ->where('company_id', '!=', $this->empresa->id)
            ->first();

        if (! $otro) {
            $this->markTestSkipped('La base de desarrollo no tiene un usuario de otra empresa.');
        }

        $this->actingAs($otro);

        $this->get(route('hotel.folio.print', $estadia))->assertNotFound();
    }

    /**
     * Sin sesión el documento no sale.
     *
     * Se comprueba por lo que NO devuelve y no por un código concreto: a quién
     * se redirige un invitado es cosa del panel, y amarrar esta prueba a un
     * 302 o un 403 la volvería frágil por algo que no tiene que ver con el
     * hotel. Lo que importa es que la cuenta —con el nombre y el documento de
     * una persona dentro— no se entregue.
     */
    public function test_la_cuenta_pide_sesion(): void
    {
        $estadia = $this->aloja();

        auth()->logout();

        $respuesta = $this->get(route('hotel.folio.print', $estadia));

        $this->assertNotSame(200, $respuesta->getStatusCode());
        $this->assertStringNotContainsString($estadia->fullNumber(), $respuesta->getContent());
    }

    /**
     * La tarjeta de registro lista a todos los que duermen, no solo al que
     * paga, y lleva la autorización de tratamiento de datos.
     */
    public function test_la_tarjeta_de_registro_lista_a_los_huespedes(): void
    {
        $titular = $this->huesped('Ana ZZ', 'Pérez ZZ');
        $acompanante = $this->huesped('Luis ZZ', 'Pérez ZZ');

        $estadia = $this->aloja();

        app(HotelStayEngine::class)->registrarHuespedes(
            $estadia,
            [$titular->id, $acompanante->id],
            (int) $titular->id,
        );

        $this->get(route('hotel.registration.print', $estadia))
            ->assertOk()
            ->assertSee('Ana ZZ')
            ->assertSee('Luis ZZ')
            ->assertSee('Ley 1581 de 2012')
            ->assertSee('Tarjeta de registro', escape: false);
    }

    /** La guía pública del módulo abre sin sesión. */
    public function test_la_guia_del_modulo_abre(): void
    {
        auth()->logout();

        $this->get(route('docs.hotel'))
            ->assertOk()
            ->assertSee('Hoteles')
            ->assertSee('RevPAR');
    }

    /** Y la del parqueadero sigue abriendo: comparten plantilla. */
    public function test_la_guia_del_parqueadero_sigue_abriendo(): void
    {
        auth()->logout();

        $this->get(route('docs.parking'))
            ->assertOk()
            ->assertSee('Parqueadero');
    }

    /**
     * El plural también lleva a la guía.
     *
     * La del parqueadero es `/docs/parqueadero` y el módulo se llama
     * «Hoteles», así que quien va a buscar esta escribe `/docs/hoteles`. Antes
     * eso era un 404, y a un prospecto al que se le cae el enlace no se le
     * ocurre probar el singular: cierra la pestaña.
     */
    public function test_el_plural_lleva_a_la_guia(): void
    {
        auth()->logout();

        $this->get('/docs/hoteles')
            ->assertRedirect('/docs/hotel');
    }

    // --------------------------------------------------------- auxiliares

    private function aloja(): Stay
    {
        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->addDay()->toDateString(),
        ]);

        $this->limpiar[] = function () use ($estadia) {
            DB::table('hotel_stay_guests')->where('stay_id', $estadia->id)->delete();
            DB::table('hotel_stay_charges')->where('stay_id', $estadia->id)->delete();
            DB::table('hotel_stays')->where('id', $estadia->id)->delete();
        };

        return $estadia;
    }

    private function huesped(string $nombre, string $apellido): Guest
    {
        $huesped = Guest::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'first_name' => $nombre,
            'last_name' => $apellido,
            'nationality' => 'Colombiana',
        ]);

        $this->limpiar[] = function () use ($huesped) {
            DB::table('hotel_stay_guests')->where('guest_id', $huesped->id)->delete();
            DB::table('hotel_guests')->where('id', $huesped->id)->delete();
        };

        return $huesped;
    }
}
