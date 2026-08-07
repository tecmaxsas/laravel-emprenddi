<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\OrderTaking\MacDulcesImporter;
use App\Support\ModuleGate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Fallback HTTP puro para el importador MAC DULCES cuando Livewire falla
 * con 'This page has expired' (checksum invalidado por cache clear /
 * restart del container). Este controller no depende del state de
 * Livewire y es inmune a issues de session/CSRF prolongados.
 */
class OrderTakingImportController extends Controller
{
    /**
     * Pagina standalone (sin Filament ni Livewire) con form HTML puro.
     * Se usa cuando la UI de Filament falla por checksum invalidado.
     */
    public function form(): View
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->can('order_taking.manage'), 403);
        abort_unless(ModuleGate::active('order_taking'), 403);

        return view('order-taking.quick-import');
    }

    public function submit(Request $request, MacDulcesImporter $importer): RedirectResponse
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->can('order_taking.manage'), 403);
        abort_unless(ModuleGate::active('order_taking'), 403);

        $request->validate([
            'precios' => 'required|file|mimes:xlsx,xls|max:10240',
            'clientes' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        // Guardamos temporalmente en el disk 'local' (storage/app/) y
        // resolvemos el path absoluto via Storage::path() — evita bugs de
        // storage_path() cuando el docroot del container no es /var/www/html.
        $disk = Storage::disk('local');
        $preciosRelative = $request->file('precios')->storeAs(
            'tmp/order-taking-imports',
            'precios_'.time().'.xlsx',
            'local',
        );
        $clientesRelative = $request->file('clientes')->storeAs(
            'tmp/order-taking-imports',
            'clientes_'.time().'.xlsx',
            'local',
        );

        try {
            $result = $importer->import(
                (int) Auth::user()->company_id,
                $disk->path($preciosRelative),
                $disk->path($clientesRelative),
            );
        } catch (\Throwable $e) {
            return redirect()->route('order-taking.import.form')
                ->with('import_error', $e->getMessage());
        }

        return redirect()->route('order-taking.import.form')
            ->with('import_result', $result);
    }
}
