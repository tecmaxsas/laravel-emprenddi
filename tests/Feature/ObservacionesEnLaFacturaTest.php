<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Parking\ParkingTerminal;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\VehicleType;
use App\Models\Product;
use App\Models\SaleInvoice;
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
 * Las observaciones de la factura rápida se imprimen.
 *
 * El cajero escribe «pagó con billete de 50» o «se le cobró media hora» y
 * eso tiene que llegar al papel que se lleva el cliente. Antes el campo
 * existía en la pantalla y no salía a ninguna parte: en la pestaña de
 * parqueo ni siquiera llegaba a la factura.
 *
 * LA MITAD IMPORTANTE DE ESTE ARCHIVO ES LO QUE **NO** SE IMPRIME.
 *
 * La factura ya tenía una columna `notes` rotulada «Notas internas», y ahí
 * es donde el sistema escribe cosas como el motivo de una anulación.
 * Resolver esto imprimiendo `notes` —que era el atajo obvio— habría hecho
 * que toda factura con una anotación interna empezara a mostrársela al
 * cliente. Por eso son dos campos distintos, y por eso hay una prueba que
 * vigila que sigan separados.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ObservacionesEnLaFacturaTest extends TestCase
{
    private Company $empresa;

    private User $cajero;

    private Location $sede;

    private ParkingLot $parqueadero;

    private Product $producto;

    private int $cuentaCaja = 0;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararParqueadero();

        $this->actingAs($this->cajero);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->empresa);

        $this->abrirCaja();
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

    /** La pestaña de parqueo lleva las observaciones a la factura. */
    public function test_la_factura_rapida_de_parqueo_guarda_las_observaciones(): void
    {
        $this->facturaRapidaDeParqueo('Pagó con billete de 50');

        $factura = $this->ultimaFactura();

        $this->assertSame('Pagó con billete de 50', $factura->observations);
    }

    /** Y la de productos también. */
    public function test_la_factura_rapida_de_productos_guarda_las_observaciones(): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->call('cambiarTipoDirecto', 'productos')
            ->call('addExtra', $this->producto->id)
            ->set('directForm.notes', 'Se lo lleva el lunes')
            ->call('processDirectInvoice');

        $this->assertSame('Se lo lleva el lunes', $this->ultimaFactura()->observations);
    }

    /**
     * El concepto del documento no se mezcla con la observación.
     *
     * `description` es «Parqueo placa ABC123» y ya sale como línea del
     * tiquete. Pegarle la observación daría un renglón que dice dos cosas.
     */
    public function test_la_observacion_no_se_mezcla_con_el_concepto(): void
    {
        $this->facturaRapidaDeParqueo('Pagó con billete de 50');

        $factura = $this->ultimaFactura();

        $this->assertStringNotContainsString('billete', (string) $factura->description,
            'La observación tiene su propia columna; el concepto se queda limpio.');
    }

    /** Sin observaciones, la columna queda nula y no un texto vacío. */
    public function test_sin_observaciones_la_columna_queda_nula(): void
    {
        $this->facturaRapidaDeParqueo('   ');

        $this->assertNull($this->ultimaFactura()->observations);
    }

    // ------------------------------------------------------- la impresión

    /** El tiquete imprime la observación. */
    public function test_el_tiquete_imprime_la_observacion(): void
    {
        $this->facturaRapidaDeParqueo('Pagó con billete de 50');

        $this->get('/app/pos/print/'.$this->ultimaFactura()->id)
            ->assertOk()
            ->assertSee('Observaciones')
            ->assertSee('Pagó con billete de 50');
    }

    /**
     * Y NO imprime las notas internas.
     *
     * Es el guarda que impide el atajo: resolver esto imprimiendo `notes`
     * sacaría al papel del cliente el motivo de una anulación y cualquier
     * otra cosa que el negocio escribió para sí mismo.
     */
    public function test_el_tiquete_no_imprime_las_notas_internas(): void
    {
        $this->facturaRapidaDeParqueo('Pagó con billete de 50');

        $factura = $this->ultimaFactura();
        $factura->update(['notes' => 'OJO: este cliente quedó debiendo la vez pasada']);

        $this->get('/app/pos/print/'.$factura->id)
            ->assertOk()
            ->assertSee('Pagó con billete de 50')
            ->assertDontSee('quedó debiendo');
    }

    /**
     * Las dos impresoras dicen lo mismo.
     *
     * El tiquete se arma en dos sitios —el HTML del navegador y el ESC/POS
     * de la impresora térmica—. Si la observación estuviera solo en uno, se
     * vería o no según cómo imprima cada negocio, y esa es la clase de
     * diferencia que nadie descubre hasta que un cliente reclama por algo
     * que «sí estaba escrito».
     */
    public function test_las_dos_impresoras_imprimen_la_observacion(): void
    {
        $html = file_get_contents(resource_path('views/pos/print-ticket.blade.php'));
        $escpos = file_get_contents(app_path('Services/Restaurant/RestaurantReceiptPrinter.php'));

        $this->assertStringContainsString('invoice->observations', $html,
            'El tiquete del navegador tiene que imprimir la observación.');

        $this->assertStringContainsString('invoice->observations', $escpos,
            'Y el de la impresora térmica también, o se vería solo en uno de los dos.');
    }

    /** Las notas de la ENTRADA son del parqueadero, no del cliente. */
    public function test_las_notas_de_la_entrada_no_llegan_a_la_factura(): void
    {
        $componente = Livewire::test(ParkingTerminal::class)
            ->call('openQuickEntry')
            ->set('entryForm.plate', 'XYZ789')
            ->set('entryForm.notes', 'Rayón en la puerta derecha')
            ->call('registerEntry');

        $sesion = DB::table('parking_sessions')
            ->where('company_id', $this->empresa->id)
            ->where('plate', 'XYZ789')
            ->first();

        $this->assertNotNull($sesion, 'No se registró la entrada.');
        $this->assertSame('Rayón en la puerta derecha', $sesion->notes,
            'La observación de la entrada sí se guarda: es del parqueadero.');

        $componente->call('selectSession', $sesion->id)
            ->set('exitForm.amount_override', 5000)
            ->set('exitForm.account_id', $this->cuentaCaja)
            ->call('processExit');

        $factura = $this->ultimaFactura();

        $this->assertNotNull($factura, 'No se emitió la factura de la salida.');
        $this->assertNull($factura->observations,
            'El rayón de la puerta lo escribió el operario para el parqueadero, '
            .'no para que el cliente lo lea en su tiquete.');
    }

    // --------------------------------------------------------- auxiliares

    private function facturaRapidaDeParqueo(string $observaciones): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->set('directForm.plate', 'ABC123')
            ->set('directForm.amount', 8000)
            ->set('directForm.notes', $observaciones)
            ->set('exitForm.account_id', $this->cuentaCaja)
            ->call('processDirectInvoice');
    }

    private function ultimaFactura(): ?SaleInvoice
    {
        return SaleInvoice::query()
            ->where('company_id', $this->empresa->id)
            ->latest('id')->first();
    }

    private function abrirCaja(): void
    {
        $turno = CashRegisterSession::create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->cajero->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }

    private function prepararParqueadero(): void
    {
        $this->empresa = Company::create([
            'nit' => 'ZZ'.random_int(1000000, 9999999),
            'name' => 'Parqueadero ZZ observaciones',
            'document_type' => 'nit',
            'organization_type' => 'juridica',
            'regime_type' => 'comun',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'active_modules' => ['parking'],
            'active' => true,
        ]);

        $this->sede = Location::create([
            'company_id' => $this->empresa->id,
            'name' => 'Sede ZZ obs',
            'code' => 'ZZO'.random_int(100, 999),
            'is_main' => true,
            'active' => true,
        ]);

        $this->parqueadero = ParkingLot::create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZO'.random_int(100, 999),
            'name' => 'Lote ZZ obs',
            'total_capacity' => 10,
            'default_location_id' => $this->sede->id,
            'default_invoice_kind' => 'pos',
            'active' => true,
        ]);

        VehicleType::create([
            'company_id' => $this->empresa->id,
            'code' => VehicleType::CODE_CAR,
            'name' => 'Carro ZZ',
            'sort_order' => 10,
            'active' => true,
        ]);

        $this->cajero = User::create([
            'company_id' => $this->empresa->id,
            'name' => 'Cajero ZZ obs',
            'email' => 'cajerozzo'.random_int(10000, 99999).'@zz.test',
            'password' => Hash::make('zz'),
            'active' => true,
        ]);

        CompanyRoles::provision($this->empresa);
        CompanyRoles::assign($this->cajero, 'cashier');

        $cuentas = collect([
            ['110505', 'Caja general ZZ', 'activo', 'debit'],
            ['1305', 'Clientes ZZ', 'activo', 'debit'],
            ['413500', 'Ingresos ZZ', 'ingreso', 'credit'],
        ])->map(fn (array $c) => Account::create([
            'company_id' => $this->empresa->id,
            'code' => $c[0],
            'name' => $c[1],
            'type' => $c[2],
            'nature' => $c[3],
            'level' => 5,
            'accepts_movements' => true,
            'active' => true,
        ]));

        $this->cuentaCaja = (int) $cuentas->first()->id;

        $this->producto = Product::create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZOB'.random_int(10000, 99999),
            'name' => 'Gaseosa ZZ obs',
            'type' => 'good',
            'unit_of_measure' => 'unit',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 5000,
            'active' => true,
        ]);

        $this->resolucionPos();

        $this->limpiar[] = function () use ($cuentas) {
            $asientos = DB::table('journal_entries')
                ->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();

            $facturas = SaleInvoice::query()->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('payments')->whereIn('paymentable_id', $facturas)
                ->where('paymentable_type', SaleInvoice::class)->delete();
            DB::table('sale_invoice_lines')->whereIn('sale_invoice_id', $facturas)->delete();
            DB::table('parking_sessions')->where('company_id', $this->empresa->id)->delete();
            DB::table('sale_invoices')->whereIn('id', $facturas)->delete();
            DB::table('cash_register_sessions')->where('company_id', $this->empresa->id)->delete();

            $roles = Role::query()->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->whereIn('id', $roles)->delete();

            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('products')->where('company_id', $this->empresa->id)->delete();
            DB::table('accounts')->whereIn('id', $cuentas->pluck('id'))->delete();
            DB::table('third_parties')->where('company_id', $this->empresa->id)->delete();
            DB::table('vehicle_types')->where('company_id', $this->empresa->id)->delete();
            DB::table('parking_lots')->where('id', $this->parqueadero->id)->delete();
            DB::table('locations')->where('id', $this->sede->id)->delete();
            DB::table('users')->where('company_id', $this->empresa->id)->delete();
            DB::table('companies')->where('id', $this->empresa->id)->delete();
        };
    }

    private function resolucionPos(): void
    {
        $resolucion = DB::table('dian_resolutions')->insertGetId([
            'company_id' => $this->empresa->id,
            'document_type_id' => 1,
            'document_type_name' => 'Factura de venta',
            'kind' => 'pos',
            'prefix' => 'ZZO',
            'resolution_number' => 'ZZ-OBS',
            'range_from' => 1,
            'range_to' => 999999,
            'date_from' => now()->subYear()->toDateString(),
            'date_to' => now()->addYear()->toDateString(),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enlace = DB::table('dian_location_resolutions')->insertGetId([
            'location_id' => $this->sede->id,
            'dian_resolution_id' => $resolucion,
            'current_consecutive' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->limpiar[] = function () use ($enlace, $resolucion) {
            DB::table('dian_location_resolutions')->where('id', $enlace)->delete();
            DB::table('dian_resolutions')->where('id', $resolucion)->delete();
        };
    }
}
