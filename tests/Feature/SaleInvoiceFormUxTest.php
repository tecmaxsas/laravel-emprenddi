<?php

namespace Tests\Feature;

use App\Filament\App\Resources\PurchaseInvoiceResource\Pages\CreatePurchaseInvoice;
use App\Filament\App\Resources\SaleInvoiceResource\Pages\CreateSaleInvoice;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryEngine;
use App\Support\CurrentCompany;
use App\Support\StockPreview;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Crear una factura de venta sin pelear con la pantalla.
 *
 * Cinco molestias que costaban tiempo real de digitación:
 *
 *   1. No se veía el stock, así que había que salir a inventario y volver — o
 *      descubrir que la venta era imposible con el cliente esperando.
 *   2. Un cliente nuevo obligaba a abandonar la factura a medio armar.
 *   3. Un producto nuevo, igual.
 *   4. Los campos numéricos cambiaban de valor al hacer scroll con el cursor
 *      encima, y los totales salían mal sin que nadie tocara nada.
 *   5. Un clic en el menú se llevaba veinte líneas ya digitadas.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class SaleInvoiceFormUxTest extends TestCase
{
    private Company $company;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------------------- 1. el stock

    /** Muestra lo que hay y lo que va a quedar. */
    public function test_muestra_el_stock_actual_y_el_que_quedara(): void
    {
        $producto = $this->producto();
        $this->cargarStock($producto, 12);

        $html = (string) StockPreview::paraLinea($producto->id, $this->sede->id, cantidad: 5);

        $this->assertStringContainsString('12', $html, 'Falta el stock actual.');
        $this->assertStringContainsString('7', $html, 'Falta el stock que quedará tras la venta.');
        $this->assertStringContainsString($this->sede->name, $html);
    }

    /**
     * Si la venta deja el saldo en rojo, se ve en rojo.
     *
     * Es el caso que justifica todo el renglón: verlo mientras se digita evita
     * la venta que se descubre imposible con el cliente esperando.
     */
    public function test_avisa_cuando_la_venta_deja_el_stock_en_negativo(): void
    {
        $producto = $this->producto();
        $this->cargarStock($producto, 3);

        $html = (string) StockPreview::paraLinea($producto->id, $this->sede->id, cantidad: 10);

        $this->assertStringContainsString('sp-negativo', $html,
            'Vender 10 de 3 tiene que verse distinto de una venta normal.');
        $this->assertStringContainsString('-7', $html);
    }

    /** Un producto que no controla inventario lo dice, en vez de mostrar ceros. */
    public function test_un_producto_sin_inventario_lo_dice(): void
    {
        $servicio = $this->producto(controlaInventario: false);

        $html = (string) StockPreview::paraLinea($servicio->id, $this->sede->id, cantidad: 1);

        $this->assertStringContainsString('no controla inventario', $html);
    }

    /** Sin producto elegido no hay nada que mostrar. */
    public function test_sin_producto_no_muestra_nada(): void
    {
        $this->assertNull(StockPreview::paraLinea(null, $this->sede->id, 1));
    }

    /** Solo se descuenta de la sede que vende. */
    public function test_solo_descuenta_de_la_sede_que_vende(): void
    {
        $otraSede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('id', '!=', $this->sede->id)
            ->first();

        if (! $otraSede) {
            $this->markTestSkipped('La empresa de desarrollo tiene una sola sede.');
        }

        $producto = $this->producto();
        $this->cargarStock($producto, 10, $this->sede);
        $this->cargarStock($producto, 4, $otraSede);

        $html = (string) StockPreview::paraLinea($producto->id, $this->sede->id, cantidad: 6);

        // La que vende baja de 10 a 4; la otra se queda en 4 sin flecha.
        $this->assertStringContainsString($otraSede->name, $html,
            'Si en la sede que vende no alcanza, lo primero es saber si hay en otra.');
        $this->assertStringContainsString('→', $html);
    }

    /**
     * La cantidad va con su unidad de medida.
     *
     * «10» a secas no dice si son diez unidades o diez kilos, y en un inventario
     * esa diferencia decide si la venta se puede despachar.
     */
    public function test_muestra_la_unidad_de_medida(): void
    {
        $porKilo = $this->producto(unidad: 'kg');
        $this->cargarStock($porKilo, 8);

        $html = (string) StockPreview::paraLinea($porKilo->id, $this->sede->id, cantidad: 3);

        $this->assertStringContainsString('kg', $html);
    }

    /**
     * El nombre de la sede y el número no van en la misma línea.
     *
     * Las sedes se llaman cosas como «MUNDO ELECTRONICO CR 10 11 45». Pegados,
     * nadie distingue el «10» de la carrera del «10» de las unidades: eso fue
     * justo lo que pasó con la primera versión.
     */
    public function test_el_nombre_de_la_sede_no_se_mezcla_con_la_cantidad(): void
    {
        $producto = $this->producto();
        $this->cargarStock($producto, 10);

        $html = (string) StockPreview::paraLinea($producto->id, $this->sede->id, cantidad: 0);

        // Cada dato en su propio contenedor: el nombre arriba, la cifra abajo.
        $this->assertStringContainsString('sp-sede', $html);
        $this->assertStringContainsString('sp-cifras', $html);

        $this->assertStringNotContainsString(
            '</div> 10',
            $html,
            'El nombre y la cantidad no pueden quedar pegados en el mismo renglón.',
        );
    }

    /** Se distingue la sede desde la que se está vendiendo. */
    public function test_se_ve_cual_es_la_sede_que_vende(): void
    {
        $producto = $this->producto();
        $this->cargarStock($producto, 5);

        $html = (string) StockPreview::paraLinea($producto->id, $this->sede->id, cantidad: 1);

        $this->assertStringContainsString('vende aquí', $html);
    }

    // --------------------------------------- 2 y 3. crear sin salir

    /** El selector de cliente permite crear uno nuevo. */
    public function test_se_puede_crear_un_cliente_desde_la_factura(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/App/Resources/SaleInvoiceResource.php')
        );

        $this->assertStringContainsString('createOptionModalHeading(\'Nuevo cliente\')', $fuente,
            'Sin esto hay que abandonar la factura a medio armar para registrar al cliente.');
    }

    /** Y el de producto, igual. */
    public function test_se_puede_crear_un_producto_desde_la_factura(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/App/Resources/SaleInvoiceResource.php')
        );

        $this->assertStringContainsString('createOptionModalHeading(\'Nuevo producto\')', $fuente);
    }

    // ------------------------------------------------- 4. el scroll

    /**
     * Ningún campo numérico puede cambiar de valor al hacer scroll.
     *
     * Un `input[type=number]` con el foco puesto responde a la rueda del ratón
     * sumando o restando. El usuario baja por la factura, el cursor pasa por
     * encima de una cantidad, y el total cambia sin que nadie escriba nada. Se
     * descubre al cuadrar, con toda la factura ya digitada.
     */
    public function test_ningun_campo_numerico_cambia_con_el_scroll(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/App/Resources/SaleInvoiceResource.php')
        );

        $numericos = substr_count($fuente, '->numeric()');
        $protegidos = substr_count($fuente, 'onwheel');

        $this->assertGreaterThan(0, $numericos, 'El formulario perdió sus campos numéricos.');

        $this->assertSame($numericos, $protegidos,
            "Hay {$numericos} campos numéricos y solo {$protegidos} protegidos del scroll. "
            .'El que falte va a cambiar de valor solo.');
    }

    // ---------------------------------------------- 5. salir sin perder

    /**
     * La pantalla pregunta antes de irse con cambios sin guardar.
     *
     * Se usa el guardián de Filament, que compara un hash de los datos del
     * formulario: sabe de verdad si algo cambió, a diferencia de una versión
     * casera que marca «sucio» con cualquier tecla y pregunta también cuando el
     * usuario escribió y borró.
     */
    public function test_avisa_antes_de_salir_con_cambios_sin_guardar(): void
    {
        $metodo = new \ReflectionMethod(CreateSaleInvoice::class, 'hasUnsavedDataChangesAlert');
        $metodo->setAccessible(true);

        $this->assertTrue($metodo->invoke(new CreateSaleInvoice),
            'Sin esto, un clic en el menú se lleva veinte líneas ya digitadas.');

        $vista = file_get_contents(
            resource_path('views/filament/app/pages/create-sale-invoice.blade.php')
        );

        $this->assertStringContainsString('unsaved-data-changes-alert', $vista,
            'La vista propia tiene que incluir el guardián de Filament.');
    }

    /**
     * La pantalla se puede guardar.
     *
     * No basta con buscar el texto del botón: al escribir una vista propia me
     * dejé fuera el `<form>` y los botones, y la pantalla quedó sin forma de
     * guardar. Se comprueba el formulario y el botón de envío, que es lo que de
     * verdad falta cuando esto se rompe.
     */
    public function test_la_pantalla_tiene_boton_para_guardar(): void
    {
        $html = Livewire::test(CreateSaleInvoice::class)->assertOk()->html();

        $this->assertStringContainsString('wire:submit="create"', $html,
            'Sin el <form> el formulario no se envía a ninguna parte.');

        $this->assertStringContainsString('type="submit"', $html,
            'La pantalla quedó sin botón de guardar.');

        $this->assertStringContainsString('Guardar borrador', $html);
    }

    /** La factura creada nace en borrador, no emitida. */
    public function test_la_factura_nace_en_borrador(): void
    {
        $metodo = new \ReflectionMethod(CreateSaleInvoice::class, 'mutateFormDataBeforeCreate');

        $this->assertStringContainsString("\$data['status'] = 'draft'",
            file_get_contents((new \ReflectionClass(CreateSaleInvoice::class))->getFileName()),
            'Si naciera contabilizada, «guardar borrador» seria mentira.');

        $this->assertTrue($metodo->isProtected());
    }

    /** La pantalla de crear sigue abriendo con la vista propia. */
    public function test_la_pantalla_abre(): void
    {
        Livewire::test(CreateSaleInvoice::class)->assertOk();
    }

    // ------------------------------------------ lo mismo en compras

    /**
     * En una compra el stock **sube**.
     *
     * Es la misma pregunta con el signo cambiado: al recibir mercancia uno
     * quiere confirmar con cuanto queda la sede, no con cuanto quedaba antes.
     */
    public function test_en_una_compra_el_stock_sube(): void
    {
        $producto = $this->producto();
        $this->cargarStock($producto, 12);

        $html = (string) StockPreview::paraLinea(
            $producto->id, $this->sede->id, cantidad: 5, entra: true,
        );

        $this->assertStringContainsString('17', $html, 'Recibir 5 sobre 12 deja 17.');
        $this->assertStringContainsString('entra aquí', $html,
            'En una compra la sede recibe, no vende.');
    }

    /** Y una compra nunca deja el saldo en rojo. */
    public function test_una_compra_no_deja_el_stock_negativo(): void
    {
        $producto = $this->producto();
        $this->cargarStock($producto, 1);

        $html = (string) StockPreview::paraLinea(
            $producto->id, $this->sede->id, cantidad: 100, entra: true,
        );

        // Sin el bloque de estilos: ahi `sp-negativo` aparece siempre como
        // regla CSS, y buscarlo en el HTML completo no prueba nada.
        $tarjetas = preg_replace('/<style>.*/s', '', $html);

        $this->assertStringNotContainsString('sp-negativo', $tarjetas);
        $this->assertStringContainsString('101', $tarjetas);
    }

    /** La factura de compra tiene las mismas comodidades que la de venta. */
    public function test_la_factura_de_compra_tiene_las_mismas_mejoras(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/App/Resources/PurchaseInvoiceResource.php')
        );

        $this->assertStringContainsString('createOptionModalHeading(\'Nuevo proveedor\')', $fuente,
            'Sin esto hay que abandonar la factura para registrar al proveedor.');

        $this->assertStringContainsString('createOptionModalHeading(\'Nuevo producto\')', $fuente);

        $this->assertStringContainsString('StockPreview::paraLinea', $fuente,
            'Falta ver cuanto stock queda al recibir la mercancia.');

        $numericos = substr_count($fuente, '->numeric()');
        $protegidos = substr_count($fuente, 'onwheel');

        $this->assertSame($numericos, $protegidos,
            "Hay {$numericos} campos numericos y solo {$protegidos} protegidos del scroll.");
    }

    /**
     * Y tambien se puede guardar y avisa antes de salir.
     *
     * Registrar una compra exige caja abierta —es una regla de negocio que ya
     * existia—, asi que la prueba abre una: sin ella la pantalla redirige y no
     * se estaria midiendo nada.
     */
    public function test_la_pantalla_de_compra_guarda_y_avisa(): void
    {
        $this->abrirCaja();

        $html = Livewire::test(CreatePurchaseInvoice::class)->assertOk()->html();

        $this->assertStringContainsString('wire:submit="create"', $html,
            'Sin el <form> el formulario no se envia a ninguna parte.');
        $this->assertStringContainsString('type="submit"', $html,
            'La pantalla quedo sin boton de guardar.');
        $this->assertStringContainsString('Guardar borrador', $html);

        $metodo = new \ReflectionMethod(CreatePurchaseInvoice::class, 'hasUnsavedDataChangesAlert');
        $metodo->setAccessible(true);

        $this->assertTrue($metodo->invoke(new CreatePurchaseInvoice));
    }

    // --------------------------------------------------------- auxiliares

    /** Deja una caja abierta mientras dure la prueba. */
    private function abrirCaja(): void
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')
            ->where('id', $turno->id)->delete();
    }

    private function producto(bool $controlaInventario = true, string $unidad = 'unit'): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZUX'.random_int(10000, 99999),
            'name' => 'ZZ Producto UX',
            'type' => $controlaInventario ? 'good' : 'service',
            'unit_of_measure' => $unidad,
            'track_inventory' => $controlaInventario,
            'is_sellable' => true,
            'default_sale_price' => 10000,
            'default_purchase_price' => 5000,
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($producto) {
            DB::table('inventory_movements')->where('product_id', $producto->id)->delete();
            DB::table('product_locations')->where('product_id', $producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($producto->id)->forceDelete();
        };

        return $producto;
    }

    private function cargarStock(Product $producto, float $cantidad, ?Location $sede = null): void
    {
        app(InventoryEngine::class)->addMovement(
            $producto,
            $sede ?? $this->sede,
            [
                'type' => 'adjustment_in',
                'quantity' => $cantidad,
                'unit_cost' => 5000,
                'date' => now()->toDateString(),
                'description' => 'ZZ carga de prueba',
            ],
        );
    }
}
