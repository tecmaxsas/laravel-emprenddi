<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Escanear dos productos seguidos en el POS.
 *
 * La pistola teclea el código y manda Enter. El segundo escaneo fallaba con
 * «ese código no coincide con ningún producto», y recargar la página lo
 * arreglaba — la señal de que el problema era de estado, no de datos.
 *
 * La causa: el buscador está atado con `wire:model.live.debounce.250ms`. Al
 * escanear, el servidor vaciaba `productSearch`, pero el envío pendiente del
 * debounce llegaba DESPUÉS con el código anterior y lo volvía a poner. El
 * siguiente escaneo se pegaba detrás —«20142018»— y no existía.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PosBarcodeScanTest extends TestCase
{
    private Company $company;

    private User $user;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        // El POS de retail redirige si la empresa tiene restaurante o
        // parqueadero: ahi el flujo de venta es otro. Se apagan para la prueba
        // y se devuelven en tearDown.
        $this->forzarPosDeRetail();

        $this->abrirCaja();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** Dos escaneos seguidos agregan los dos productos. */
    public function test_dos_escaneos_seguidos_agregan_los_dos(): void
    {
        $uno = $this->producto('ZZBC-001');
        $dos = $this->producto('ZZBC-002');

        $componente = Livewire::test(PosTerminal::class)
            ->assertOk()
            ->call('addByBarcode', $uno->code)
            ->call('addByBarcode', $dos->code);

        $carrito = collect($componente->get('cart'))->pluck('product_id');

        $this->assertContains($uno->id, $carrito->all());
        $this->assertContains($dos->id, $carrito->all(),
            'El segundo escaneo es el que fallaba.');
    }

    /**
     * El buscador queda vacío después de cada escaneo.
     *
     * Si no, el siguiente código se pega detrás del anterior y el POS dice que
     * no existe — que es exactamente lo que pasaba.
     */
    public function test_el_buscador_queda_vacio_tras_escanear(): void
    {
        $producto = $this->producto('ZZBC-003');

        Livewire::test(PosTerminal::class)
            ->set('productSearch', $producto->code)
            ->call('addByBarcode', $producto->code)
            ->assertSet('productSearch', '');
    }

    /**
     * Y también cuando el código no existe.
     *
     * Dejando el código malo en el buscador, el siguiente escaneo se le pegaba
     * detrás y fallaba también: un error se volvía dos.
     */
    public function test_el_buscador_se_vacia_aunque_el_codigo_no_exista(): void
    {
        Livewire::test(PosTerminal::class)
            ->set('productSearch', 'ZZ-NO-EXISTE')
            ->call('addByBarcode', 'ZZ-NO-EXISTE')
            ->assertSet('productSearch', '');
    }

    /**
     * La pantalla vacía el input en el navegador, sin esperar al servidor.
     *
     * Es la mitad del arreglo: el envío pendiente del debounce volvía a poner
     * el código viejo después de que el servidor ya lo había limpiado.
     */
    public function test_la_pantalla_vacia_el_input_sin_esperar_al_servidor(): void
    {
        $vista = file_get_contents(
            resource_path('views/filament/app/pages/pos-terminal.blade.php')
        );

        $this->assertStringContainsString("\$event.target.value = '';", $vista,
            'El input se vacía en el navegador, antes de llamar al servidor.');

        $this->assertStringContainsString("\$wire.set('productSearch', '', false)", $vista,
            'Sin esto, el envío pendiente del debounce repone el código viejo.');
    }

    /**
     * El buscador tiene color de texto propio en claro y en oscuro.
     *
     * Sin declararlo heredaba un color que en modo oscuro quedaba del mismo
     * tono que el fondo: el cajero escribía a ciegas y solo veía lo tecleado
     * al seleccionarlo.
     */
    public function test_el_buscador_se_lee_en_modo_oscuro(): void
    {
        $vista = file_get_contents(
            resource_path('views/filament/app/pages/pos-terminal.blade.php')
        );

        preg_match('/id="pos-search-input".*?class="([^"]*)"/s', $vista, $m);

        $this->assertNotEmpty($m, 'No se encontró el buscador de productos.');
        $this->assertStringContainsString('text-gray-900', $m[1]);
        $this->assertStringContainsString('dark:text-white', $m[1]);
    }

    // --------------------------------------------------------- auxiliares

    private function forzarPosDeRetail(): void
    {
        $original = $this->company->active_modules;

        $sinOtrosPos = array_values(array_diff($original ?? [], ['restaurant', 'parking']));

        if ($sinOtrosPos === ($original ?? [])) {
            return;
        }

        $this->company->update(['active_modules' => $sinOtrosPos]);
        app(CurrentCompany::class)->set($this->company->fresh());
        auth()->user()?->unsetRelation('company');

        $this->limpiar[] = function () use ($original) {
            $this->company->update(['active_modules' => $original]);
            app(CurrentCompany::class)->set($this->company->fresh());
            auth()->user()?->unsetRelation('company');
        };
    }

    private function producto(string $codigo): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => 'ZZ Producto '.$codigo,
            'type' => 'good',
            'active' => true,
            'is_sellable' => true,
            'track_inventory' => false,
            'default_sale_price' => 10000,
        ]);

        $this->limpiar[] = fn () => DB::table('products')->where('id', $producto->id)->delete();

        return $producto;
    }

    private function abrirCaja(): void
    {
        $abierto = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('cashier_user_id', $this->user->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->exists();

        if ($abierto) {
            return;
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }
}
