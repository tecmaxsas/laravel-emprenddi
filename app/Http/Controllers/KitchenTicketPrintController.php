<?php

namespace App\Http\Controllers;

use App\Models\Restaurant\KitchenTicket;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Render de la comanda de cocina para impresion por navegador.
 * URL: /app/restaurant/kot/print/{ticket}
 *
 * Es el equivalente de PosPrintController para el KOT: se usa cuando ninguna
 * impresora activa enruta los productos de la orden, para que la cocina no se
 * quede sin comanda. Auto-imprime al cargar.
 */
class KitchenTicketPrintController extends Controller
{
    public function show(int $ticket): View
    {
        abort_unless(Auth::user()->can('restaurant.use'), 403);

        $ticket = KitchenTicket::query()
            ->with(['order.table', 'order.server', 'printer'])
            ->whereHas('order', fn ($q) => $q->where('company_id', Auth::user()->company_id))
            ->findOrFail($ticket);

        return view('restaurant.print-kot', ['ticket' => $ticket]);
    }
}
