<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Settings;
use App\Filament\App\Resources\PurchaseInvoiceResource;
use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Que la sección de descuento global aparezca donde debe.
 *
 * Es una funcionalidad detrás de un interruptor, y ese es justo el tipo de cosa
 * que se rompe en silencio: el ajuste se guarda, nadie mira el formulario, y el
 * campo simplemente no está. Aquí se comprueba en las dos pantallas y en los dos
 * estados.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class GlobalDiscountFormTest extends TestCase
{
    private Company $company;

    /** @var array<string, mixed>|null */
    private ?array $ajustesOriginales = null;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => $u->can('sales.view') && $u->can('purchases.view'))
            ?? $this->markTestSkipped('Ningún usuario de la base ve ventas y compras.');

        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->ajustesOriginales = $this->company->settings;
    }

    protected function tearDown(): void
    {
        if ($this->ajustesOriginales !== null || isset($this->company)) {
            DB::table('companies')->where('id', $this->company->id)
                ->update(['settings' => json_encode($this->ajustesOriginales)]);
        }

        parent::tearDown();
    }

    /** Encendido, el campo está en las dos pantallas. */
    public function test_encendido_aparece_en_venta_y_en_compra(): void
    {
        $this->configurar(sales: true, purchases: true);

        foreach ($this->recursos() as $nombre => $recurso) {
            $this->assertNotEmpty($this->seccion($recurso),
                "{$nombre}: el ajuste está encendido y la sección no aparece.");
        }
    }

    /** Apagado, no estorba: la sección no se arma. */
    public function test_apagado_no_aparece(): void
    {
        $this->configurar(sales: false, purchases: false);

        foreach ($this->recursos() as $nombre => $recurso) {
            $this->assertSame([], $this->seccion($recurso),
                "{$nombre}: el ajuste está apagado y la sección sigue ahí.");
        }
    }

    /** Los dos interruptores son independientes. */
    public function test_se_puede_encender_solo_en_compras(): void
    {
        $this->configurar(sales: false, purchases: true);

        $this->assertSame([], $this->seccion(SaleInvoiceResource::class));
        $this->assertNotEmpty($this->seccion(PurchaseInvoiceResource::class));
    }

    /**
     * Que la sección exista no basta: hay que comprobar que el formulario la
     * incluya. Es exactamente el descuido que deja el ajuste guardado y el
     * campo invisible.
     */
    public function test_las_dos_pantallas_arman_la_seccion_en_su_formulario(): void
    {
        foreach ($this->recursos() as $nombre => $recurso) {
            $codigo = file_get_contents((new \ReflectionClass($recurso))->getFileName());

            $this->assertStringContainsString('self::globalDiscountSection()', $codigo,
                "{$nombre} no incluye la sección de descuento global en su formulario.");

            $this->assertStringContainsString('self::previewTotals($get)', $codigo,
                "{$nombre}: los totales del pie no tienen en cuenta el descuento global.");
        }
    }

    /** El ajuste está en la pantalla de configuraciones, no solo en la base. */
    public function test_el_ajuste_se_ve_en_configuraciones(): void
    {
        Livewire::test(Settings::class)
            ->assertOk()
            ->assertSee('Descuentos globales en facturas')
            ->assertFormFieldExists('discounts_sales')
            ->assertFormFieldExists('discounts_purchases');
    }

    /** @return array<string, class-string> */
    private function recursos(): array
    {
        return [
            'Facturas de venta' => SaleInvoiceResource::class,
            'Facturas de compra' => PurchaseInvoiceResource::class,
        ];
    }

    /**
     * La sección tal como la arma el resource. Se llega por reflexión porque es
     * protegida: montar la página completa redirige por las guardas del panel y
     * no dice nada sobre el formulario.
     */
    private function seccion(string $recurso): array
    {
        $metodo = new \ReflectionMethod($recurso, 'globalDiscountSection');
        $metodo->setAccessible(true);

        return $metodo->invoke(null);
    }

    private function configurar(bool $sales, bool $purchases): void
    {
        $ajustes = $this->ajustesOriginales ?? [];
        $ajustes['discounts'] = ['sales' => $sales, 'purchases' => $purchases];

        $this->company->update(['settings' => $ajustes]);
        app(CurrentCompany::class)->set($this->company->fresh());
    }
}
