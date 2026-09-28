<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Dian\DianInvoiceSender;
use App\Services\Dian\SaleInvoiceUblBuilder;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cuando el proveedor contesta sin la respuesta de la DIAN.
 *
 * Pasa: apidian acusa el envío, devuelve el CUFE y el bloque ResponseDian no
 * viene —timeout de la DIAN, respuesta asíncrona—. Eso NO es un rechazo, y
 * marcarlo como tal tenía tres consecuencias encadenadas: el CUFE se
 * descartaba, sin CUFE desaparecía el botón «Consultar estado DIAN» que lo
 * resolvía, y quedaba a la vista «Reenviar a DIAN», que duplica un documento
 * ya radicado.
 *
 * Le pasó a la factura ARI20 de IMPORTACIONES ARI: estaba en la DIAN, con
 * CUFE, y aquí figuraba rechazada.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class InvoiceDianNoResponseTest extends TestCase
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
            'name' => 'ZZ CLIENTE SIN RESPUESTA',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($cliente->id)->forceDelete();

        $id = DB::table('sale_invoices')->insertGetId([
            'company_id' => $company->id,
            'location_id' => $sede->id,
            'third_party_id' => $cliente->id,
            'prefix' => 'ZZSR',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'electronic',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => 'posted',
            'payment_status' => 'pendiente',
            'subtotal' => 100000,
            'total' => 100000,
            'net_payable' => 100000,
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

    /** El CUFE se guarda: es lo único que permite comprobar qué pasó. */
    public function test_sin_respuesta_de_dian_se_guarda_el_cufe(): void
    {
        $cufe = str_repeat('a', 96);

        $factura = $this->procesar(['cufe' => $cufe]);

        $this->assertSame($cufe, $factura->cufe,
            'Sin CUFE no hay forma de consultar el estado: el botón ni siquiera aparece.');
        $this->assertSame(SaleInvoice::DIAN_SENT, $factura->dian_status,
            'No saber qué pasó no es lo mismo que estar rechazada.');
        $this->assertStringContainsString('Consultar estado DIAN', (string) $factura->dian_error_message);
    }

    /** Y con CUFE ya no se ofrece reenviar: duplicaría lo que está radicado. */
    public function test_con_cufe_y_enviada_no_se_puede_reenviar(): void
    {
        $factura = $this->procesar(['cufe' => str_repeat('b', 96)]);

        $this->assertFalse($factura->canResendToDian());
    }

    /** Sin CUFE no viajó nada, así que reintentar sigue siendo lo correcto. */
    public function test_sin_cufe_se_puede_reintentar(): void
    {
        $factura = $this->procesar(['message' => 'Procesando']);

        $this->assertSame(SaleInvoice::DIAN_SENT, $factura->dian_status);
        $this->assertNull($factura->cufe);
        $this->assertTrue($factura->canResendToDian());
    }

    /** @param array<string, mixed> $data */
    private function procesar(array $data): SaleInvoice
    {
        $sender = new DianInvoiceSender(app(SaleInvoiceUblBuilder::class));

        $metodo = new ReflectionMethod($sender, 'processResponse');
        $metodo->setAccessible(true);
        $metodo->invoke($sender, $this->factura, ['ok' => true, 'data' => $data]);

        return $this->factura->fresh();
    }
}
