<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\Delivery\DeliveryProductProvisioner;
use App\Support\CurrentCompany;
use App\Support\PosSettings;
use App\Support\ProductosInternos;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\FuerzaPosDeRetail;
use Tests\TestCase;

/**
 * Cobrar el domicilio desde el POS de retail.
 *
 * **Por qué es una línea de factura y no una columna.** El domicilio lo paga
 * el cliente: lleva IVA, entra en la base gravable, viaja a la DIAN y se
 * contabiliza contra una cuenta de ingreso. Una columna colgada del total
 * obliga a acordarse de ella en cada uno de esos sitios, y el día que alguien
 * agregue un reporte se le va a olvidar.
 *
 * No es teoría: el módulo de restaurante guarda el domicilio en
 * `orders.delivery_fee` y esa plata **no llega a la factura**. Se cobra y no
 * se factura.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class DomicilioPosTest extends TestCase
{
    use FuerzaPosDeRetail;

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

        $this->fuerzaPosDeRetail($this->empresa, $this->limpiar);
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

    /** Apagado por defecto: a quien no reparte, el botón le sobra. */
    public function test_viene_apagado(): void
    {
        $this->assertFalse(PosSettings::deliveryEnabled($this->empresa->id));
    }

    /** El producto se crea solo y es idempotente. */
    public function test_el_producto_se_crea_una_sola_vez(): void
    {
        $uno = $this->productoDeDomicilio();
        $dos = app(DeliveryProductProvisioner::class)->ensure($this->empresa);

        $this->assertSame($uno->id, $dos->id, 'Activar dos veces no puede dejar dos productos.');
        $this->assertSame(DeliveryProductProvisioner::CODE, $uno->code);
        $this->assertFalse((bool) $uno->track_inventory, 'Un domicilio no es mercancía.');
        $this->assertTrue((bool) $uno->is_sellable);
    }

    /**
     * No sale en el catálogo público.
     *
     * Es la vitrina que el negocio le muestra a sus clientes por un enlace.
     * «Domicilio — $0» ahí no es un producto: es plomería del sistema a la
     * vista.
     */
    public function test_no_se_publica_en_el_catalogo(): void
    {
        $this->assertTrue(ProductosInternos::es(DeliveryProductProvisioner::CODE));
    }

    /** Con el ajuste encendido, el botón agrega la línea con el valor configurado. */
    public function test_agrega_la_linea_con_el_valor_configurado(): void
    {
        $this->encender(valor: 8000);
        $this->turnoAbierto();

        $componente = Livewire::test(PosTerminal::class)->call('toggleDomicilio');

        $carrito = $componente->instance()->cart;

        $this->assertCount(1, $carrito);
        $this->assertSame(DeliveryProductProvisioner::CODE, $carrito[0]['code']);
        $this->assertEqualsWithDelta(8000, (float) $carrito[0]['unit_price'], 0.01);
        $this->assertTrue($componente->instance()->tieneDomicilio);
    }

    /** Volver a pulsarlo lo quita: es un interruptor, no un acumulador. */
    public function test_pulsarlo_dos_veces_lo_quita(): void
    {
        $this->encender(valor: 8000);
        $this->turnoAbierto();

        $componente = Livewire::test(PosTerminal::class)
            ->call('toggleDomicilio')
            ->call('toggleDomicilio');

        $this->assertSame([], $componente->instance()->cart);
        $this->assertFalse($componente->instance()->tieneDomicilio);
    }

    /**
     * El domicilio suma al total de la venta.
     *
     * Es la razón de ser de todo esto: en el restaurante se cobra y no se
     * factura.
     */
    public function test_suma_al_total_de_la_venta(): void
    {
        $this->encender(valor: 8000);
        $this->turnoAbierto();

        $componente = Livewire::test(PosTerminal::class)->call('toggleDomicilio');

        $carrito = $componente->instance()->cart;

        $this->assertGreaterThan(0, (float) $carrito[0]['total'],
            'La línea tiene que valer algo: si vale cero, el domicilio se cobró y no se facturó.');
    }

    /** Con el ajuste apagado, el botón no hace nada aunque lo invoquen. */
    public function test_apagado_no_agrega_nada(): void
    {
        $this->turnoAbierto();

        $componente = Livewire::test(PosTerminal::class)->call('toggleDomicilio');

        $this->assertSame([], $componente->instance()->cart,
            'Ocultar el botón no basta: una acción de Livewire se invoca desde el navegador.');
    }

    // --------------------------------------------------------- auxiliares

    private function encender(float $valor): void
    {
        $original = $this->empresa->settings;

        $nuevos = $original ?? [];
        $nuevos['pos']['delivery_enabled'] = true;
        $nuevos['pos']['delivery_fee'] = $valor;

        $this->empresa->update(['settings' => $nuevos]);
        app(CurrentCompany::class)->set($this->empresa->fresh());

        $this->limpiar[] = function () use ($original) {
            $this->empresa->update(['settings' => $original]);
            app(CurrentCompany::class)->set($this->empresa->fresh());
        };

        $this->productoDeDomicilio();
    }

    private function productoDeDomicilio(): Product
    {
        $existia = Product::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('code', DeliveryProductProvisioner::CODE)
            ->exists();

        $producto = app(DeliveryProductProvisioner::class)->ensure($this->empresa);

        if (! $existia) {
            $this->limpiar[] = fn () => Product::withoutGlobalScopes()
                ->whereKey($producto->id)->forceDelete();
        }

        return $producto;
    }

    private function turnoAbierto(): void
    {
        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_OPEN, 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $sede->id,
            'cashier_user_id' => $this->usuario->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }
}
