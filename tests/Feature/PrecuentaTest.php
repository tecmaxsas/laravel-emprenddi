<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PrecuentaSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La precuenta: lo que el mesero le lleva al cliente antes de cobrarle.
 *
 * El cliente revisa lo consumido y solo entonces se factura. Sin ella se
 * factura primero y se discute después, que es cuando el error cuesta una
 * anulación —o una nota crédito, si la factura ya se fue a la DIAN—.
 *
 * Lo que más se prueba aquí no es que imprima bien, sino **que no se confunda
 * con una factura**: sale por la misma impresora, se parece y lleva los mismos
 * totales.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PrecuentaTest extends TestCase
{
    private Company $company;

    private User $user;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
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

    // ------------------------------------------------- no es una factura

    /**
     * La leyenda se imprime siempre.
     *
     * Es lo único que distingue la precuenta de una factura a los ojos de
     * quien la recibe. Ante la DIAN, entregar algo que parezca factura sin
     * serlo es un problema.
     */
    public function test_dice_con_todas_las_letras_que_no_es_una_factura(): void
    {
        $orden = $this->orden();

        $html = $this->get(route('restaurant.precheck', ['order' => $orden->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('NO es una factura de venta', $html);
        $this->assertStringContainsString('Precuenta', $html);
    }

    /** Y no hay forma de quitarla desde la configuración. */
    public function test_la_leyenda_no_es_configurable(): void
    {
        $vista = file_get_contents(resource_path('views/restaurant/precheck.blade.php'));

        $this->assertStringContainsString('NO es una factura de venta', $vista,
            'La leyenda vive en la vista, no en un ajuste que alguien pueda vaciar.');

        // El pie sí sale de la configuración; la leyenda no puede salir de ahí.
        $this->assertStringNotContainsString("config['aviso']", $vista);
        $this->assertStringNotContainsString("config['legend']", $vista);
    }

    // ------------------------------------------------------- el contenido

    /** Muestra lo consumido, con cantidades y precios. */
    public function test_muestra_lo_consumido(): void
    {
        $orden = $this->orden();
        $this->item($orden, 'ZZ Bandeja paisa', 2, 32000);

        $html = $this->get(route('restaurant.precheck', ['order' => $orden->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('ZZ Bandeja paisa', $html);
        $this->assertStringContainsString('64.000', $html);
    }

    /**
     * La propina va aparte del consumo, nunca sumada al total.
     *
     * Sumarla la convierte en algo que el cliente cree que debe. En Colombia
     * es voluntaria.
     */
    public function test_la_propina_va_aparte_del_consumo(): void
    {
        $this->configurar(['enabled' => true, 'show_tip' => true, 'tip_percent' => 10]);

        $orden = $this->orden(100000);

        $html = $this->get(route('restaurant.precheck', ['order' => $orden->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('CONSUMO', $html);
        $this->assertStringContainsString('Propina sugerida', $html);
        $this->assertStringContainsString('10.000', $html, 'El 10% de 100.000.');
        $this->assertStringContainsString('110.000', $html, 'Y el total con propina, como segunda cifra.');
    }

    /** Se puede apagar la sugerencia de propina. */
    public function test_se_puede_no_sugerir_propina(): void
    {
        $this->configurar(['enabled' => true, 'show_tip' => false, 'tip_percent' => 10]);

        $orden = $this->orden(100000);

        $html = $this->get(route('restaurant.precheck', ['order' => $orden->id]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Propina sugerida', $html);
        $this->assertSame(0.0, PrecuentaSettings::propinaSugerida(100000));
    }

    /** El pie es el que escribió el restaurante. */
    public function test_imprime_el_pie_configurado(): void
    {
        $this->configurar([
            'enabled' => true,
            'footer' => 'ZZ Gracias por visitarnos. La propina es voluntaria.',
        ]);

        $orden = $this->orden();

        $html = $this->get(route('restaurant.precheck', ['order' => $orden->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('ZZ Gracias por visitarnos', $html);
    }

    /** Sin configurar, el pie trae ya escrita la advertencia de la propina. */
    public function test_el_pie_por_defecto_advierte_lo_de_la_propina(): void
    {
        $this->assertStringContainsString('VOLUNTARIA', PrecuentaSettings::PIE_POR_DEFECTO,
            'Un campo vacío se queda vacío, y entonces la advertencia no sale.');
    }

    // ------------------------------------------------------------ guardas

    /** Una orden ya cerrada no tiene precuenta: tiene factura. */
    public function test_una_orden_cerrada_no_imprime_precuenta(): void
    {
        $orden = $this->orden();
        $orden->update(['status' => Order::STATUS_CLOSED]);

        $this->get(route('restaurant.precheck', ['order' => $orden->id]))
            ->assertStatus(409);
    }

    /** Con la precuenta desactivada, la ruta no responde. */
    public function test_desactivada_no_se_puede_imprimir(): void
    {
        $this->configurar(['enabled' => false]);

        $orden = $this->orden();

        $this->get(route('restaurant.precheck', ['order' => $orden->id]))
            ->assertStatus(403);
    }

    /** Una orden de otra empresa no se puede mirar. */
    public function test_no_se_ve_la_orden_de_otra_empresa(): void
    {
        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base de desarrollo.');
        }

        $ajena = $this->orden(50000, $otra->id);

        $this->get(route('restaurant.precheck', ['order' => $ajena->id]))
            ->assertStatus(404);
    }

    /** No cambia el estado de la orden: es una hoja para la mesa. */
    public function test_imprimirla_no_cambia_la_orden(): void
    {
        $orden = $this->orden();
        $estado = $orden->status;

        $this->get(route('restaurant.precheck', ['order' => $orden->id]))->assertOk();
        $this->get(route('restaurant.precheck', ['order' => $orden->id]))->assertOk();

        $this->assertSame($estado, $orden->fresh()->status,
            'Se imprime las veces que haga falta: el cliente la pide y la revisa.');
    }

    // --------------------------------------------------------- auxiliares

    /** @param  array<string, mixed>  $precheck */
    private function configurar(array $precheck): void
    {
        $original = $this->company->settings;

        $settings = $original ?? [];
        $settings['restaurant'] = array_merge($settings['restaurant'] ?? [], [
            'precheck' => array_merge(['enabled' => true], $precheck),
        ]);

        $this->company->update(['settings' => $settings]);
        app(CurrentCompany::class)->set($this->company->fresh());
        auth()->user()?->unsetRelation('company');

        $this->limpiar[] = function () use ($original) {
            $this->company->update(['settings' => $original]);
            app(CurrentCompany::class)->set($this->company->fresh());
            auth()->user()?->unsetRelation('company');
        };
    }

    private function orden(float $total = 50000, ?int $companyId = null): Order
    {
        $companyId ??= $this->company->id;

        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $companyId)->orderBy('id')->firstOrFail();

        $orden = Order::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'location_id' => $sede->id,
            'prefix' => 'ZZORD',
            'number' => random_int(900000, 999999),
            'status' => Order::STATUS_OPEN,
            'opened_at' => now(),
            'server_user_id' => $this->user->id,
            'subtotal' => $total,
            'discount_total' => 0,
            'tax_total' => 0,
            'tip_amount' => 0,
            'total' => $total,
        ]);

        $this->limpiar[] = fn () => DB::table('restaurant_orders')->where('id', $orden->id)->delete();

        return $orden;
    }

    private function item(Order $orden, string $descripcion, float $cantidad, float $precio): void
    {
        $item = OrderItem::create([
            'restaurant_order_id' => $orden->id,
            'line_number' => 1,
            'description' => $descripcion,
            'quantity' => $cantidad,
            'unit_price' => $precio,
            'subtotal' => $cantidad * $precio,
            'total' => $cantidad * $precio,
        ]);

        $this->limpiar[] = fn () => DB::table('restaurant_order_items')->where('id', $item->id)->delete();
    }
}
