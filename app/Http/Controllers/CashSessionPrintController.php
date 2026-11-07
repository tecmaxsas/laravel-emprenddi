<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Models\InvoiceTemplate;
use App\Services\Cash\CashSessionSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * El tiquete del arqueo de caja, para imprimir.
 *
 * URL: /app/caja/arqueo/{session}
 *
 * POR QUE HACE FALTA. El cierre de caja se cuadra con el cajero delante y
 * termina en una cifra que alguien firma. Hasta ahora esa cifra vivia solo en
 * la pantalla: para dejar constancia habia que copiarla a mano a un cuaderno,
 * y lo que se copia a mano es lo que despues nadie puede verificar.
 *
 * EL DESGLOSE POR METODO DE PAGO ES EL PUNTO. Un arqueo que solo dice
 * «esperado $1.200.000» no sirve para cuadrar: la plata del cajon es solo el
 * efectivo, y el resto hay que confrontarlo contra el datafono y el extracto.
 * Sin el desglose, la diferencia aparece pero no se sabe de donde sale.
 *
 * Las cifras salen de `CashSessionSummary`, el mismo que calcula el esperado
 * al cerrar. Si este controlador las contara por su cuenta, un dia el tiquete
 * impreso y la pantalla dirian cosas distintas, y ese papel esta firmado.
 */
class CashSessionPrintController extends Controller
{
    public function show(int $session): View
    {
        $sesion = CashRegisterSession::query()
            ->with(['location.invoiceTemplate', 'cashier', 'company'])
            ->where('id', $session)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        // El mismo permiso con el que se cierra la caja: quien puede cuadrarla
        // puede imprimir su constancia.
        abort_unless(Auth::user()->can('pos.cash_close'), 403);

        $plantilla = $sesion->location?->resolveInvoiceTemplate()
            ?? InvoiceTemplate::query()
                ->where('company_id', $sesion->company_id)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->first();

        return view('cash.arqueo-ticket', [
            'sesion' => $sesion,
            'resumen' => app(CashSessionSummary::class)->compute($sesion),
            'plantilla' => $plantilla,
            'paperSize' => $plantilla?->paper_size ?? 'pos_58',
        ]);
    }
}
