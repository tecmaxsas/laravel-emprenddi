<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\Dian\CompanyConfig;
use App\Models\SaleInvoice;
use App\Services\Dian\DianDocumentDownloader;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Descargar el PDF oficial de un documento electrónico.
 *
 * La representación gráfica con CUFE y QR la genera el proveedor tecnológico
 * cuando la DIAN autoriza. Emprenddi la pide en el momento; no la guarda.
 *
 * Todo depende de armar bien el nombre del archivo —el endpoint es uno solo y lo
 * que decide qué devuelve es cómo se llama lo que se le pide— y de no creerle al
 * código HTTP: el proveedor responde 200 con `success: false` cuando no
 * encuentra el archivo, así que un `$response->successful()` a secas daría por
 * buena una descarga vacía.
 *
 * No toca la red: todas las respuestas del proveedor van simuladas.
 */
class DianPdfDownloadTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->asegurarConfigDian();
    }

    /** Una nota crédito se pide como NCS-, no como FES-. */
    public function test_la_nota_credito_se_pide_con_su_propio_prefijo(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.7 contenido', 200, ['Content-Type' => 'application/pdf'])]);

        $archivo = app(DianDocumentDownloader::class)->pdf($this->nota('credit', 'NC', 1));

        $this->assertSame('NCS-NC1.pdf', $archivo['filename']);
        $this->assertStringStartsWith('%PDF', $archivo['contents']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/download/')
            && str_ends_with($request->url(), 'NCS-NC1.pdf'));
    }

    /** Y una nota débito como NDS-. */
    public function test_la_nota_debito_usa_el_prefijo_de_debito(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.7', 200)]);

        $archivo = app(DianDocumentDownloader::class)->pdf($this->nota('debit', 'ND', 7));

        $this->assertSame('NDS-ND7.pdf', $archivo['filename']);
    }

    /** La factura electrónica es FES-; la POS es POSS-. */
    public function test_las_facturas_distinguen_electronica_de_pos(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.7', 200)]);

        $electronica = app(DianDocumentDownloader::class)->pdf($this->factura('electronic', 'FE', 42));
        $this->assertSame('FES-FE42.pdf', $electronica['filename']);

        $pos = app(DianDocumentDownloader::class)->pdf($this->factura('pos', 'POS', 9));
        $this->assertSame('POSS-POS9.pdf', $pos['filename']);
    }

    /**
     * Si el proveedor ya nos dijo cómo se llama el archivo, se usa ese nombre.
     *
     * Es más confiable que reconstruirlo: si el proveedor cambia la convención,
     * la respuesta del envío sigue trayendo el nombre bueno.
     */
    public function test_se_prefiere_el_nombre_que_dio_el_proveedor(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.7', 200)]);

        $nota = $this->nota('credit', 'NC', 1);
        $nota->dian_response = ['urlinvoicepdf' => 'NCS-OTRONOMBRE99.pdf'];

        $archivo = app(DianDocumentDownloader::class)->pdf($nota);

        $this->assertSame('NCS-OTRONOMBRE99.pdf', $archivo['filename']);
    }

    /** Aunque venga enterrado en la respuesta. */
    public function test_encuentra_el_nombre_aunque_venga_anidado(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.7', 200)]);

        $nota = $this->nota('credit', 'NC', 1);
        $nota->dian_response = ['data' => ['respuesta' => ['urlinvoicepdf' => 'NCS-ANIDADO5.pdf']]];

        $this->assertSame('NCS-ANIDADO5.pdf',
            app(DianDocumentDownloader::class)->pdf($nota)['filename']);
    }

    /**
     * La trampa: 200 OK con `success: false` es «no lo encontré».
     *
     * Sin esta comprobación el usuario descargaría un PDF de dos líneas de JSON
     * y el visor le diría que el archivo está dañado.
     */
    public function test_un_doscientos_con_success_false_no_es_un_pdf(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'message' => 'No se encontro el archivo: NCS-NC1.pdf',
        ], 200)]);

        try {
            app(DianDocumentDownloader::class)->pdf($this->nota('credit', 'NC', 1));
            $this->fail('Debió rechazar la respuesta en vez de entregar el JSON como PDF.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('todavía no tiene el archivo', $e->getMessage(),
                'El mensaje debe sugerir esperar, que es la causa real casi siempre.');
        }
    }

    /** Un documento que la DIAN no aceptó no tiene PDF que descargar. */
    public function test_sin_aceptacion_de_la_dian_no_hay_pdf(): void
    {
        $nota = $this->nota('credit', 'NC', 1);
        $nota->dian_status = CreditDebitNote::DIAN_PENDING;

        $razon = app(DianDocumentDownloader::class)->motivoParaNoDescargar($nota);

        $this->assertNotNull($razon);
        $this->assertStringContainsString('todavía no está aceptado', $razon);
    }

    /** Ni uno aceptado pero sin CUFE, que es una contradicción. */
    public function test_sin_cufe_tampoco(): void
    {
        $nota = $this->nota('credit', 'NC', 1);
        $nota->cufe = null;

        $this->assertStringContainsString('no tiene CUFE',
            app(DianDocumentDownloader::class)->motivoParaNoDescargar($nota));
    }

    /** Un documento aceptado y con CUFE sí se puede pedir. */
    public function test_un_documento_aceptado_no_tiene_impedimento(): void
    {
        $this->assertNull(
            app(DianDocumentDownloader::class)->motivoParaNoDescargar($this->nota('credit', 'NC', 1))
        );
    }

    /** El NIT del emisor va sin dígito de verificación. */
    public function test_el_nit_va_sin_digito_de_verificacion(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.7', 200)]);

        app(DianDocumentDownloader::class)->pdf($this->nota('credit', 'NC', 1));

        $nit = preg_replace('/\D/', '', (string) $this->company->nit);

        Http::assertSent(fn ($request) => str_contains($request->url(), "/download/{$nit}/"));
    }

    // --------------------------------------------------------- auxiliares

    /** Documentos en memoria: nada de esto necesita tocar la base. */
    private function nota(string $tipo, string $prefijo, int $numero): CreditDebitNote
    {
        $nota = new CreditDebitNote([
            'company_id' => $this->company->id,
            'type' => $tipo,
            'prefix' => $prefijo,
            'number' => $numero,
        ]);

        $nota->dian_status = CreditDebitNote::DIAN_ACCEPTED;
        $nota->cufe = str_repeat('a', 96);
        $nota->setRelation('company', $this->company);

        return $nota;
    }

    private function factura(string $kind, string $prefijo, int $numero): SaleInvoice
    {
        $factura = new SaleInvoice([
            'company_id' => $this->company->id,
            'invoice_kind' => $kind,
            'prefix' => $prefijo,
            'number' => $numero,
        ]);

        $factura->dian_status = SaleInvoice::DIAN_ACCEPTED;
        $factura->cufe = str_repeat('b', 96);
        $factura->setRelation('company', $this->company);

        return $factura;
    }

    private function asegurarConfigDian(): void
    {
        $config = CompanyConfig::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        if ($config && $config->api_token) {
            return;
        }

        // Sin token el descargador se niega antes de llegar al HTTP, y estas
        // pruebas no estarían midiendo lo que creen medir.
        $this->markTestSkipped('La empresa de desarrollo no tiene configurada la facturación electrónica.');
    }
}
