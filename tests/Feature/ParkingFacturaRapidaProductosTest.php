<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Parking\ParkingTerminal;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSession;
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
 * Vender productos desde el terminal del parqueadero.
 *
 * Un parqueadero vende más cosas que parqueo: tintos, gaseosas, una lavada,
 * un repuesto. La única forma de cobrarlas era colgarlas de la salida de un
 * vehículo, así que si el cliente no venía en carro —o ya se había ido— se
 * cobraban por fuera del sistema: no entraban al inventario, ni al arqueo,
 * ni a la factura.
 *
 * La factura rápida ahora es de parqueo o de productos. Y lo que NO hace es
 * tan importante como lo que hace: una venta de productos no crea sesión de
 * parqueo. Inventar un vehículo con placa vacía y cero minutos por cada
 * gaseosa ensuciaría la ocupación, la rotación y el promedio de estadía.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ParkingFacturaRapidaProductosTest extends TestCase
{
    private Company $empresa;

    private User $cajero;

    private Location $sede;

    private ParkingLot $parqueadero;

    private Product $producto;

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

    // ----------------------------------------------------- vender productos

    /** Dos gaseosas se facturan sin que haya vehículo de por medio. */
    public function test_factura_productos_sin_vehiculo(): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->call('cambiarTipoDirecto', 'productos')
            ->call('addExtra', $this->producto->id)
            ->call('adjustExtraQty', 0, 1)
            ->call('processDirectInvoice');

        $factura = $this->facturaDeLaEmpresa();

        $this->assertNotNull($factura, 'No se generó la factura.');
        $this->assertEqualsWithDelta(10000, (float) $factura->total, 0.01, 'Dos unidades de $5.000.');
        $this->assertSame(SaleInvoice::ORIGIN_PARKING, $factura->origin);
    }

    /**
     * Y NO crea una sesión de parqueo.
     *
     * Es la mitad del punto: sesiones fantasma con placa vacía ensucian la
     * ocupación y el promedio de estadía del parqueadero.
     */
    public function test_vender_productos_no_inventa_un_vehiculo(): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->call('cambiarTipoDirecto', 'productos')
            ->call('addExtra', $this->producto->id)
            ->call('processDirectInvoice');

        $this->assertSame(0, ParkingSession::query()
            ->where('company_id', $this->empresa->id)->count(),
            'Una gaseosa no es un parqueo.');
    }

    /** Sin productos no factura nada. */
    public function test_sin_productos_no_factura(): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->call('cambiarTipoDirecto', 'productos')
            ->call('processDirectInvoice');

        $this->assertNull($this->facturaDeLaEmpresa());
    }

    // ---------------------------------------------------------- el parqueo

    /** El modo parqueo sigue funcionando como antes. */
    public function test_el_parqueo_suelto_sigue_funcionando(): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->set('directForm.plate', 'ZZZ123')
            ->set('directForm.amount', 7000)
            ->call('processDirectInvoice');

        $factura = $this->facturaDeLaEmpresa();

        $this->assertNotNull($factura);
        $this->assertEqualsWithDelta(7000, (float) $factura->total, 0.01);
        $this->assertSame(1, ParkingSession::query()
            ->where('company_id', $this->empresa->id)->count(),
            'El cobro de un parqueo sí deja su sesión.');
    }

    /** Un parqueo puede llevar productos en la misma factura. */
    public function test_el_parqueo_admite_productos_en_la_misma_factura(): void
    {
        Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->set('directForm.plate', 'ZZZ456')
            ->set('directForm.amount', 7000)
            ->call('addExtra', $this->producto->id)
            ->call('processDirectInvoice');

        $factura = $this->facturaDeLaEmpresa();

        $this->assertNotNull($factura);
        $this->assertEqualsWithDelta(12000, (float) $factura->total, 0.01,
            '$7.000 de parqueo más una gaseosa de $5.000.');
        $this->assertCount(2, $factura->lines);
    }

    // ------------------------------------------------------- el interruptor

    /**
     * Cambiar de modo limpia lo que se deja atrás.
     *
     * Un valor de parqueo olvidado al pasar a productos se cobraría sin que
     * nadie lo vea, porque el campo ya no está en pantalla.
     */
    public function test_cambiar_de_modo_limpia_lo_que_queda_atras(): void
    {
        $pagina = Livewire::test(ParkingTerminal::class)
            ->call('openDirectInvoice')
            ->set('directForm.plate', 'ZZZ789')
            ->set('directForm.amount', 9000)
            ->call('cambiarTipoDirecto', 'productos');

        $this->assertSame('', $pagina->get('directForm.plate'));
        $this->assertNull($pagina->get('directForm.amount'));

        $pagina->call('addExtra', $this->producto->id)
            ->call('cambiarTipoDirecto', 'parqueo');

        $this->assertSame([], $pagina->get('exitExtras'),
            'Los productos agregados en el otro modo no pueden colarse en el cobro del parqueo.');
    }

    // ------------------------------------------------------- el modo oscuro

    /**
     * Los campos numéricos tienen color propio.
     *
     * La regla decía `input[type=text]`, así que «Valor a cobrar» y «Con
     * cuánto paga» —que son `type=number`— se quedaban sin fondo ni color.
     * En modo oscuro el tema les ponía el texto blanco sobre fondo blanco y
     * el cajero digitaba sin ver lo que escribía.
     *
     * Se mira el CSS y no el render porque el color sale de una hoja de
     * estilos, no del HTML; pero el selector es exactamente lo que se rompió.
     */
    public function test_los_campos_numericos_no_quedan_invisibles(): void
    {
        $css = file_get_contents(
            resource_path('views/filament/app/pages/parking/terminal.blade.php')
        );

        $this->assertStringContainsString(
            '.pt-field input, .pt-field textarea, .pt-field select',
            $css,
            'El selector por tipo deja fuera los números: tiene que cubrir todo input.',
        );

        $this->assertStringNotContainsString('.pt-field input[type=text],', $css,
            'Si vuelve el selector por tipo, vuelve el campo invisible.');

        $this->assertStringContainsString('.pt-modal input, .pt-modal textarea, .pt-modal select', $css,
            'La red de seguridad cubre los campos que alguien agregue mañana.');
    }

    /**
     * Los selectores del modal llevan UNA flecha.
     *
     * El navegador dibuja la suya, Filament le pone otra y el parcial
     * compartido una tercera. Encima, las reglas de esta pantalla usaban la
     * forma corta `background:#fff`, que resetea también background-image,
     * -repeat, -position y -size: según qué regla ganara el orden, la flecha
     * desaparecía o salía repetida sobre el texto.
     *
     * Se mira el CSS porque el número de flechas no está en el HTML. No
     * reemplaza verlo, pero sí atrapa la regresión concreta.
     */
    public function test_los_selectores_del_modal_llevan_una_sola_flecha(): void
    {
        $css = file_get_contents(
            resource_path('views/filament/app/pages/parking/terminal.blade.php')
        );

        $this->assertStringContainsString('.pt-overlay .pt-modal select', $css,
            'La regla tiene que ganar por especificidad, no por ir después del parcial.');

        $this->assertStringContainsString('appearance:none !important', $css,
            'Sin apagar la nativa, el navegador dibuja su propia flecha encima.');

        // Solo las reglas que apuntan a campos: un `background` en el div
        // del modal es legitimo —no tiene flecha que borrar—.
        preg_match_all('/([^{}]*\b(?:input|select|textarea)\b[^{}]*)\{([^}]*)\}/i', $css, $reglas, PREG_SET_ORDER);

        foreach ($reglas as [$todo, $selector, $cuerpo]) {
            $this->assertDoesNotMatchRegularExpression(
                '/(^|[;\s])background:\s*#/i',
                $cuerpo,
                'La forma corta `background` borra la imagen de la flecha. '
                .'Usa background-color en: '.trim($selector),
            );
        }
    }

    // --------------------------------------------------------- auxiliares

    private function facturaDeLaEmpresa(): ?SaleInvoice
    {
        return SaleInvoice::query()
            ->where('company_id', $this->empresa->id)
            ->with('lines')
            ->latest('id')
            ->first();
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
            'name' => 'Parqueadero ZZ productos',
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
            'name' => 'Sede ZZ',
            'code' => 'ZZ'.random_int(100, 999),
            'is_main' => true,
            'active' => true,
        ]);

        $this->parqueadero = ParkingLot::create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZL'.random_int(100, 999),
            'name' => 'Lote ZZ',
            'total_capacity' => 10,
            'default_location_id' => $this->sede->id,
            'default_invoice_kind' => 'pos',
            'active' => true,
        ]);

        $this->cajero = User::create([
            'company_id' => $this->empresa->id,
            'name' => 'Cajero ZZ',
            'email' => 'cajerozzp'.random_int(1000, 9999).'@zz.test',
            'password' => Hash::make('zz'),
            'active' => true,
        ]);

        CompanyRoles::provision($this->empresa);
        CompanyRoles::assign($this->cajero, 'cashier');

        // Caja, cartera e ingresos: el cobro mueve las tres. Sin la 1305 el
        // motor no sabe contra que cuenta abonar y la factura no nace.
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

        $this->producto = Product::create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZPR'.random_int(1000, 9999),
            'name' => 'Gaseosa ZZ',
            'type' => 'good',
            'unit_of_measure' => 'unit',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 5000,
            'active' => true,
        ]);

        $this->resolucionPos();

        $this->limpiar[] = function () use ($cuentas) {
            // Los asientos primero: sus lineas apuntan a las cuentas, y
            // borrar una cuenta referenciada revienta por llave foranea.
            $asientos = DB::table('journal_entries')
                ->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();

            $facturas = SaleInvoice::query()->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('payments')->whereIn('paymentable_id', $facturas)
                ->where('paymentable_type', SaleInvoice::class)->delete();
            DB::table('sale_invoice_lines')->whereIn('sale_invoice_id', $facturas)->delete();
            DB::table('sale_invoices')->whereIn('id', $facturas)->delete();
            DB::table('parking_sessions')->where('company_id', $this->empresa->id)->delete();
            DB::table('cash_register_sessions')->where('company_id', $this->empresa->id)->delete();

            $roles = Role::query()->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->whereIn('id', $roles)->delete();

            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('products')->where('id', $this->producto->id)->delete();
            DB::table('accounts')->whereIn('id', $cuentas->pluck('id'))->delete();
            DB::table('parking_lots')->where('id', $this->parqueadero->id)->delete();
            DB::table('locations')->where('id', $this->sede->id)->delete();
            DB::table('users')->where('id', $this->cajero->id)->delete();
            DB::table('companies')->where('id', $this->empresa->id)->delete();
        };
    }

    /** Sin resolución POS la sede no puede emitir. */
    private function resolucionPos(): void
    {
        $resolucion = DB::table('dian_resolutions')->insertGetId([
            'company_id' => $this->empresa->id,
            'document_type_id' => 1,
            'document_type_name' => 'Factura de venta',
            'kind' => 'pos',
            'prefix' => 'ZZP',
            'resolution_number' => 'ZZ-PRUEBA',
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
