<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\Location;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Dian\CreditDebitNoteSender;
use App\Services\Dian\CreditDebitNoteUblBuilder;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Quién decide si la DIAN aceptó la nota.
 *
 * Lo decidía el CUFE: sin código no había aceptación. Pero en notas el código
 * se llama CUDE, y el sistema solo miraba `cufe`. Una nota aceptada —código 00,
 * «Procesado Correctamente»— quedaba marcada en rojo como rechazada, con las
 * notificaciones de la DIAN presentadas como errores. El cliente la daba por
 * fallida y la reenviaba; el segundo envío sí fallaba, esta vez de verdad,
 * porque el documento ya estaba radicado.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CreditNoteDianResponseTest extends TestCase
{
    private Company $company;

    private CreditDebitNote $nota;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE RESPUESTA',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($cliente->id)->forceDelete();

        // La nota exige factura referenciada. Basta con que exista: aquí no se
        // contabiliza nada, solo se interpreta la respuesta de la DIAN.
        $facturaId = DB::table('sale_invoices')->insertGetId([
            'company_id' => $this->company->id,
            'location_id' => $sede->id,
            'third_party_id' => $cliente->id,
            'prefix' => 'ZZRSPF',
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

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $facturaId)->delete();

        $this->nota = CreditDebitNote::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $sede->id,
            'third_party_id' => $cliente->id,
            'sale_invoice_id' => $facturaId,
            'type' => CreditDebitNote::TYPE_CREDIT,
            'prefix' => 'ZZRSP',
            'number' => random_int(1, 99999),
            'date' => now()->toDateString(),
            'reason_code' => 2,
            'status' => CreditDebitNote::STATUS_POSTED,
            'currency' => 'COP',
            'created_by_user_id' => auth()->id(),
        ]);

        $this->limpiar[] = fn () => DB::table('credit_debit_notes')
            ->where('id', $this->nota->id)->delete();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** El CUDE cuenta como código: es el nombre que tiene en las notas. */
    public function test_una_nota_aceptada_con_cude_queda_aceptada(): void
    {
        $nota = $this->procesar([
            'cude' => str_repeat('c', 96),
            'ResponseDian' => $this->respuestaDian('00', 'true', [
                'Regla: CBF02, Notificación: No se informo el numero de la factura referenciada',
            ]),
        ]);

        $this->assertSame(CreditDebitNote::DIAN_ACCEPTED, $nota->dian_status);
        $this->assertSame(str_repeat('c', 96), $nota->cufe);
        $this->assertStringContainsString('searchqr', (string) $nota->qr_url);
        $this->assertStringContainsString('CBF02', (string) $nota->dian_error_message,
            'Las notificaciones se guardan: dicen qué corregir para el próximo documento.');
    }

    /** Y sin código, una nota aceptada sigue siendo aceptada. */
    public function test_sin_cude_la_aceptacion_no_se_convierte_en_rechazo(): void
    {
        $nota = $this->procesar([
            'ResponseDian' => $this->respuestaDian('00', 'true', []),
        ]);

        $this->assertSame(CreditDebitNote::DIAN_ACCEPTED, $nota->dian_status,
            'La DIAN dijo «Procesado Correctamente»: que falte el código no lo vuelve un rechazo.');
        $this->assertNull($nota->cufe);
        $this->assertStringContainsString('no devolvió el CUDE', (string) $nota->dian_error_message);
    }

    /** El rechazo de verdad sigue siendo rechazo, con sus reglas a la vista. */
    public function test_un_rechazo_real_conserva_las_reglas(): void
    {
        $nota = $this->procesar([
            'ResponseDian' => $this->respuestaDian('99', 'false', [
                'Regla: CAD09e, Rechazo: La fecha de generación de la NC es diferente a la fecha de firma',
            ]),
        ]);

        $this->assertSame(CreditDebitNote::DIAN_REJECTED, $nota->dian_status);
        $this->assertStringContainsString('CAD09e', (string) $nota->dian_error_message);
    }

    // --------------------------------------------------------- auxiliares

    /** @param array<string, mixed> $data */
    private function procesar(array $data): CreditDebitNote
    {
        $sender = new CreditDebitNoteSender(app(CreditDebitNoteUblBuilder::class));

        $metodo = new ReflectionMethod($sender, 'processResponse');
        $metodo->setAccessible(true);
        $metodo->invoke($sender, $this->nota, ['ok' => true, 'data' => $data]);

        return $this->nota->fresh();
    }

    /**
     * La respuesta de la DIAN tal como llega: SOAP convertido a JSON por el
     * proveedor, con el resultado enterrado cuatro niveles abajo.
     *
     * @param  list<string>  $reglas
     * @return array<string, mixed>
     */
    private function respuestaDian(string $statusCode, string $isValid, array $reglas): array
    {
        $resultado = [
            'StatusCode' => $statusCode,
            'StatusDescription' => $statusCode === '00'
                ? 'Procesado Correctamente.'
                : 'Validación contiene errores en campos mandatorios.',
            'IsValid' => $isValid,
        ];

        if ($reglas !== []) {
            $resultado['ErrorMessage'] = ['string' => $reglas];
        }

        return ['Envelope' => ['Body' => ['SendBillSyncResponse' => ['SendBillSyncResult' => $resultado]]]];
    }
}
