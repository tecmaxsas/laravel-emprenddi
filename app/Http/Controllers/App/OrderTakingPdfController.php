<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\OrderTaking\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OrderTakingPdfController extends Controller
{
    /**
     * Genera el PDF del pedido y lo devuelve para descarga en el navegador.
     * Multitenancy: la orden debe pertenecer a la company del usuario.
     */
    public function show(int $order): Response
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->can('order_taking.use'), 403);

        $record = Order::query()
            ->with(['items.product', 'customer', 'priceList', 'seller'])
            ->where('id', $order)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $company = Auth::user()->company;

        $pdf = Pdf::loadView('order-taking.order-pdf', [
            'order' => $record,
            'company' => $company,
        ])->setPaper('a4');

        return $pdf->stream('Pedido-'.$record->fullNumber().'.pdf');
    }
}
