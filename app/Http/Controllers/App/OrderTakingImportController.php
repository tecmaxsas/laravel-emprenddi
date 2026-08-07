<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\OrderTaking\MacDulcesImporter;
use App\Support\ModuleGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fallback HTTP puro para el importador MAC DULCES cuando Livewire falla
 * con 'This page has expired' (checksum invalidado por cache clear /
 * restart del container). Este controller no depende del state de
 * Livewire y es inmune a issues de session/CSRF prolongados.
 */
class OrderTakingImportController extends Controller
{
    public function submit(Request $request, MacDulcesImporter $importer): RedirectResponse
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->can('order_taking.manage'), 403);
        abort_unless(ModuleGate::active('order_taking'), 403);

        $request->validate([
            'precios' => 'required|file|mimes:xlsx,xls|max:10240',
            'clientes' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        // Guardamos temporalmente en storage/app/tmp/ y pasamos el path
        // absoluto al importador. El importador lee via OpenSpout desde disk.
        $preciosPath = $request->file('precios')->storeAs(
            'tmp/order-taking-imports',
            'precios_'.time().'.xlsx',
            'local',
        );
        $clientesPath = $request->file('clientes')->storeAs(
            'tmp/order-taking-imports',
            'clientes_'.time().'.xlsx',
            'local',
        );

        try {
            $result = $importer->import(
                (int) Auth::user()->company_id,
                storage_path('app/'.$preciosPath),
                storage_path('app/'.$clientesPath),
            );
        } catch (\Throwable $e) {
            return redirect()->back()->with('import_error', $e->getMessage());
        }

        return redirect()->back()->with('import_result', $result);
    }
}
