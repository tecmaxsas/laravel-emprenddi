<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\OrderTaking\CatalogImportTemplateGenerator;
use App\Support\ModuleGate;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderTakingCatalogTemplateController extends Controller
{
    /**
     * Descarga una de las dos plantillas XLSX del catalogo de Toma pedidos.
     *
     * Son dos archivos y no uno con dos hojas porque el importador lee siempre
     * la primera hoja: precios y clientes se suben por separado.
     */
    public function template(string $tipo, CatalogImportTemplateGenerator $generator): StreamedResponse
    {
        abort_unless(Auth::check(), 403);
        abort_unless(ModuleGate::active('order_taking'), 403);
        abort_unless(Auth::user()?->can('order_taking.manage'), 403);
        abort_unless(in_array($tipo, [
            CatalogImportTemplateGenerator::TIPO_PRECIOS,
            CatalogImportTemplateGenerator::TIPO_CLIENTES,
        ], true), 404);

        return response()->streamDownload(
            $generator->stream($tipo),
            $generator->nombreArchivo($tipo),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
