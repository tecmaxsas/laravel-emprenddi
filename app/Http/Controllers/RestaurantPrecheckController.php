<?php

namespace App\Http\Controllers;

use App\Models\Restaurant\Order;
use App\Support\PrecuentaSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * La precuenta de una orden, lista para imprimir.
 *
 * URL: /app/restaurant/orders/{order}/precheck
 *
 * Es el documento que el mesero le lleva al cliente para que revise lo
 * consumido antes de facturar. Se imprime por el navegador, igual que la
 * comanda de cocina, para que funcione con cualquier impresora que el
 * restaurante tenga conectada.
 *
 * Se puede imprimir tantas veces como haga falta y en cualquier momento de la
 * orden: es justamente lo que se usa cuando el cliente pide «la cuenta» y
 * quiere revisarla antes de pagar. Por eso no cambia el estado de la orden ni
 * deja rastro: no es un documento del sistema, es una hoja para la mesa.
 */
class RestaurantPrecheckController extends Controller
{
    public function show(int $order): View
    {
        abort_unless(Auth::user()?->can('restaurant.use'), 403);

        $orden = Order::query()
            ->with(['items', 'table', 'zone', 'server', 'customer', 'location'])
            ->where('company_id', Auth::user()->company_id)
            ->findOrFail($order);

        // Una orden ya cerrada tiene factura: para esa esta el tiquete, que si
        // es el documento formal. Reimprimir la precuenta de algo ya cobrado
        // solo sirve para confundir a quien la reciba.
        abort_if(
            in_array($orden->status, [Order::STATUS_CLOSED, Order::STATUS_CANCELLED], true),
            409,
            'Esta orden ya está cerrada. La precuenta es para antes de facturar; '
            .'para una orden cobrada, imprime el tiquete de la factura.',
        );

        abort_unless(
            PrecuentaSettings::activa(),
            403,
            'La precuenta está desactivada. Actívala en Configuración → Restaurante.',
        );

        return view('restaurant.precheck', [
            'order' => $orden,
            'company' => Auth::user()->company,
            'config' => PrecuentaSettings::config(),
        ]);
    }
}
