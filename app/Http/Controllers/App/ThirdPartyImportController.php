<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\ThirdParties\ThirdPartyImportTemplateGenerator;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ThirdPartyImportController extends Controller
{
    /**
     * Descarga la plantilla XLSX para importacion masiva de terceros.
     * Incluye hojas de referencia (cuentas CxC/CxP, codigos DIAN)
     * pre-llenadas con datos de la empresa del usuario.
     */
    public function template(ThirdPartyImportTemplateGenerator $generator): StreamedResponse
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()?->can('third_parties.manage'), 403);
        $companyId = (int) Auth::user()->company_id;
        abort_unless($companyId, 403);

        $filename = 'plantilla-importacion-terceros-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(
            $generator->stream($companyId),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
