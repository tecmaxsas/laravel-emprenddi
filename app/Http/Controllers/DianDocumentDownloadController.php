<?php

namespace App\Http\Controllers;

use App\Models\CreditDebitNote;
use App\Models\SaleInvoice;
use App\Services\Dian\DianDocumentDownloader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega el PDF oficial que la DIAN autorizó.
 *
 * El archivo se pide al proveedor en el momento y se devuelve al navegador sin
 * guardarlo en disco: es un documento público con CUFE y QR, no tiene sentido
 * mantener copias que se desactualicen.
 *
 * Los dos modelos que llegan aquí tienen `company_id`, y la consulta lo filtra
 * explícitamente contra la empresa del usuario. No se confía solo en el scope
 * global, que es la clase de descuido con el que una empresa termina viendo un
 * documento de otra.
 */
class DianDocumentDownloadController extends Controller
{
    public function creditDebitNote(int $note): Response
    {
        $nota = CreditDebitNote::query()
            ->with('company')
            ->where('id', $note)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        abort_unless(Auth::user()->can('credit_debit_notes.view'), 403);

        return $this->entregar($nota);
    }

    public function saleInvoice(int $invoice): Response
    {
        $factura = SaleInvoice::query()
            ->with('company')
            ->where('id', $invoice)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        abort_unless(Auth::user()->can('sales.view'), 403);

        return $this->entregar($factura);
    }

    private function entregar(CreditDebitNote|SaleInvoice $documento): Response
    {
        try {
            $archivo = app(DianDocumentDownloader::class)->pdf($documento);
        } catch (RuntimeException $e) {
            Log::info('Descarga de PDF DIAN rechazada', [
                'documento' => $documento::class,
                'id' => $documento->id,
                'motivo' => $e->getMessage(),
            ]);

            // Una pantalla de error de Laravel no le dice nada al cajero. Mejor
            // el motivo en texto plano, que es corto y accionable.
            abort(422, $e->getMessage());
        }

        return response($archivo['contents'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$archivo['filename'].'"',
        ]);
    }
}
