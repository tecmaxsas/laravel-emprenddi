<?php

namespace Tests\Feature;

use App\Filament\App\Resources\PurchaseInvoiceResource\Pages\ViewPurchaseInvoice;
use App\Models\Company;
use App\Models\Location;
use App\Models\PurchaseInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El soporte de la factura del proveedor: su foto o su PDF.
 *
 * Es lo que resuelve la discusión cuando el contador pregunta por un IVA o el
 * proveedor dice que cobró otra cosa. Hasta ahora vivía en una carpeta, en un
 * cajón o en el celular de alguien.
 *
 * **Lo que más cuida esta prueba es que se pueda adjuntar DESPUÉS de
 * contabilizar.** El caso corriente es registrar la factura hoy porque hay
 * que pagarla, y que el papel llegue con el pedido tres días después. Si solo
 * se pudiera en borrador —como ocurre con todo lo demás del formulario— justo
 * el caso corriente quedaría sin soporte para siempre.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class SoporteFacturaCompraTest extends TestCase
{
    private Company $empresa;

    private Location $sede;

    private ThirdParty $proveedor;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->proveedor = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZSOP'.random_int(100000, 999999),
            'name' => 'ZZSOP Proveedor soporte',
            'is_supplier' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($this->proveedor->id)->forceDelete();
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

    /** La columna guarda varias rutas: una factura puede llegar en varias hojas. */
    public function test_guarda_varios_archivos(): void
    {
        $factura = $this->factura('draft');

        $factura->update(['attachment_paths' => ['compras/soportes/2026/11/a.jpg', 'compras/soportes/2026/11/b.pdf']]);

        $this->assertSame(
            ['compras/soportes/2026/11/a.jpg', 'compras/soportes/2026/11/b.pdf'],
            $factura->fresh()->attachment_paths,
        );
    }

    /**
     * Se adjunta con la factura YA CONTABILIZADA.
     *
     * Es la razón de que tenga acción propia en vez de ser un campo más del
     * formulario: el formulario se cierra al contabilizar, y con razón —ahí
     * ya no se tocan cantidades ni impuestos—. Pero el soporte no es dato
     * contable: es la prueba de lo que se digitó.
     */
    public function test_se_adjunta_sobre_una_factura_contabilizada(): void
    {
        $factura = $this->factura('posted');

        Livewire::test(ViewPurchaseInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('adjuntarSoporte', data: [
                'attachment_paths' => ['compras/soportes/2026/11/llego-el-jueves.jpg'],
            ]);

        $this->assertSame(
            ['compras/soportes/2026/11/llego-el-jueves.jpg'],
            $factura->fresh()->attachment_paths,
            'El papel llega después de pagar: si solo se pudiera en borrador, no entraría nunca.',
        );
    }

    /** Adjuntar no toca nada más de la factura. */
    public function test_adjuntar_no_cambia_la_factura(): void
    {
        $factura = $this->factura('posted');
        $antes = $factura->only(['status', 'total', 'net_payable', 'payment_status']);

        Livewire::test(ViewPurchaseInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('adjuntarSoporte', data: [
                'attachment_paths' => ['compras/soportes/2026/11/x.pdf'],
            ]);

        $this->assertSame($antes, $factura->fresh()->only(['status', 'total', 'net_payable', 'payment_status']),
            'El soporte es evidencia, no debe mover cifras ni estados.');
    }

    /** Y se pueden quitar todos. */
    public function test_se_pueden_quitar(): void
    {
        $factura = $this->factura('posted');
        $factura->update(['attachment_paths' => ['compras/soportes/2026/11/viejo.jpg']]);

        Livewire::test(ViewPurchaseInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('adjuntarSoporte', data: ['attachment_paths' => []]);

        $this->assertSame([], $factura->fresh()->attachment_paths ?? []);
    }

    /**
     * Los archivos van al disco público.
     *
     * Es el que el respaldo diario ya empaqueta (`storage/app/public`). En
     * otra carpeta, el día que se restaure un respaldo la contabilidad
     * volvería sin sus soportes.
     */
    public function test_el_formulario_guarda_en_el_disco_respaldado(): void
    {
        $fuente = file_get_contents(app_path('Filament/App/Resources/PurchaseInvoiceResource.php'));

        $this->assertStringContainsString("->disk('public')", $fuente);
        $this->assertStringContainsString('compras/soportes/', $fuente,
            'Por año y mes: un solo directorio con decenas de miles de archivos es lento de listar y de respaldar.');
    }

    // --------------------------------------------------------- auxiliares

    private function factura(string $estado): PurchaseInvoice
    {
        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->proveedor->id,
            'prefix' => 'ZZSOP',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => $estado,
            'payment_status' => 'pendiente',
            'subtotal' => 100000,
            'total' => 100000,
            'net_payable' => 100000,
        ]);

        $this->limpiar[] = fn () => DB::table('purchase_invoices')->where('id', $factura->id)->delete();

        return $factura->fresh();
    }
}
