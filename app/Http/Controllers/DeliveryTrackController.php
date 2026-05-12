<?php

namespace App\Http\Controllers;

use App\Models\Restaurant\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliveryTrackController extends Controller
{
    /**
     * Vista pública del estado del pedido a domicilio. Accesible vía
     * /track/{token} sin auth. El token es un Str::random(32) único por
     * orden, inopinable.
     *
     * Las queries deben hacer withoutGlobalScopes() porque no hay user
     * autenticado (sin SetActiveCompany middleware aplicado).
     */
    public function show(string $token, Request $request): View
    {
        $order = Order::query()
            ->withoutGlobalScopes()
            ->where('tracking_token', $token)
            ->where('is_delivery', true)
            ->with(['items', 'company:id,name,logo_path,phone'])
            ->first();

        abort_if(! $order, 404);

        $items = $order->items->reject(fn ($i) => $i->kitchen_status === 'cancelled');

        return view('public.delivery-track', [
            'order' => $order,
            'items' => $items,
            'meta' => $order->delivery_metadata ?? [],
        ]);
    }
}
