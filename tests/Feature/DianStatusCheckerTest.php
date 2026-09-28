<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Dian\DianStatusChecker;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Leer la respuesta de la consulta de estado, se llame como se llame.
 *
 * El resultado viene dentro de `GetStatusZipResult`, pero el proveedor no
 * siempre usa ese nombre: según el endpoint y la versión llega como
 * `GetStatusResult`. Buscar uno solo dejaba la consulta ciega ante una
 * respuesta válida, y la factura quedaba en «reintenta en unos minutos» para
 * siempre — con el agravante de que la respuesta cruda se descartaba, así que
 * no había ni cómo averiguar por qué.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class DianStatusCheckerTest extends TestCase
{
    private SaleInvoice $factura;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($company);

        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $company->id)->orderBy('id')->firstOrFail();

        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE ESTADO',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($cliente->id)->forceDelete();

        $id = DB::table('sale_invoices')->insertGetId([
            'company_id' => $company->id,
            'location_id' => $sede->id,
            'third_party_id' => $cliente->id,
            'prefix' => 'ZZST',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'electronic',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => 'posted',
            'payment_status' => 'pendiente',
            'subtotal' => 100000,
            'total' => 100000,
            'net_payable' => 100000,
            'dian_status' => SaleInvoice::DIAN_REJECTED,
            'cufe' => str_repeat('d', 96),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $id)->delete();

        $this->factura = SaleInvoice::withoutGlobalScopes()->findOrFail($id);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** La forma documentada: GetStatusZipResult. */
    public function test_lee_la_respuesta_en_get_status_zip(): void
    {
        $resultado = $this->consultar($this->cuerpo('GetStatusZipResponse', 'GetStatusZipResult', [
            'StatusCode' => '00',
            'StatusDescription' => 'Procesado Correctamente.',
            'IsValid' => 'true',
        ]));

        $this->assertSame(SaleInvoice::DIAN_ACCEPTED, $resultado['status']);
        $this->assertSame(SaleInvoice::DIAN_ACCEPTED, $this->factura->fresh()->dian_status);
    }

    /**
     * Y la otra que manda el proveedor: GetStatusResult.
     *
     * Es la respuesta real de la ARI20 de IMPORTACIONES ARI, con sus dos
     * notificaciones: autorizada, y con cosas que corregir para la próxima.
     */
    public function test_lee_tambien_la_respuesta_en_get_status(): void
    {
        $resultado = $this->consultar($this->cuerpo('GetStatusResponse', 'GetStatusResult', [
            'StatusCode' => '00',
            'StatusDescription' => 'Procesado Correctamente.',
            'StatusMessage' => 'La Factura electrónica ARI-20, ha sido autorizada.',
            'IsValid' => 'true',
            'ErrorMessage' => [
                'string' => [
                    'Regla: FAJ43b, Notificación: Nombre informado No corresponde al registrado en el RUT.',
                    'Regla: RUT01, Notificación: La validación del estado del RUT próximamente estará disponible.',
                ],
            ],
        ]));

        $this->assertSame(SaleInvoice::DIAN_ACCEPTED, $resultado['status'],
            'Con este nombre la consulta quedaba ciega y la factura no salía nunca del rojo.');

        $factura = $this->factura->fresh();
        $this->assertNotNull($factura->qr_url);
        $this->assertStringContainsString('FAJ43b', (string) $factura->dian_error_message,
            'Las notificaciones se conservan: son lo que hay que corregir para la próxima.');
    }

    /** Lo que no se entiende se guarda, que es lo único que permite arreglarlo. */
    public function test_una_respuesta_ilegible_queda_guardada_y_no_cambia_el_estado(): void
    {
        $resultado = $this->consultar(['algo' => 'que no esperábamos']);

        $this->assertFalse($resultado['ok']);
        $this->assertFalse($resultado['changed']);
        $this->assertSame(SaleInvoice::DIAN_REJECTED, $this->factura->fresh()->dian_status,
            'No poder leer la respuesta no es información sobre el documento.');
        $this->assertSame(['algo' => 'que no esperábamos'], $this->factura->fresh()->dian_response);
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @return array<string, mixed>
     */
    private function cuerpo(string $respuesta, string $clave, array $resultado): array
    {
        return ['ResponseDian' => ['Envelope' => ['Body' => [$respuesta => [$clave => $resultado]]]]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function consultar(array $data): array
    {
        $metodo = new ReflectionMethod(DianStatusChecker::class, 'processResponse');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(DianStatusChecker::class), $this->factura, ['ok' => true, 'data' => $data]);
    }
}
