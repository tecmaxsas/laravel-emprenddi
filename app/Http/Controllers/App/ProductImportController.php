<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductImportTemplateGenerator;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    /**
     * Descarga la plantilla XLSX de importacion masiva de productos.
     * Incluye las hojas de referencia (categorias/impuestos/cuentas)
     * pre-llenadas con los datos de la empresa del usuario.
     */
    public function template(ProductImportTemplateGenerator $generator): StreamedResponse
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()?->can('products.manage'), 403);
        $companyId = (int) Auth::user()->company_id;
        abort_unless($companyId, 403);

        $filename = 'plantilla-importacion-productos-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(
            $generator->stream($companyId),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
