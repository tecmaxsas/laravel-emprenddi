<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\OrderTaking\Order;
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
            ->with(['items.product', 'customer', 'priceList', 'seller', 'retentions'])
            ->where('id', $order)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $company = Auth::user()->company;

        abort_unless(
            class_exists(\Barryvdh\DomPDF\Facade\Pdf::class),
            500,
            'El paquete barryvdh/laravel-dompdf no está instalado. Ejecuta composer install en el container.',
        );

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('order-taking.order-pdf', [
            'order' => $record,
            'company' => $company,
        ])->setPaper('a4');

        return $pdf->stream('Pedido-'.$record->fullNumber().'.pdf');
    }
}
