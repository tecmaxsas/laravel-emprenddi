<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\OrderTaking\Order;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Endpoint TEMPORAL de diagnostico. Carga un pedido con todas sus
 * relaciones y devuelve como texto plano cualquier excepcion —
 * util cuando el 500 de Filament no llega al log de Laravel.
 *
 * Eliminar despues de resolver el issue.
 */
class OrderTakingDebugController extends Controller
{
    public function index(): Response
    {
        abort_unless(Auth::check(), 403);
        $companyId = (int) Auth::user()->company_id;

        $out = [];
        $out[] = "=== DEBUG LISTADO PEDIDOS ===";
        $out[] = "Empresa: {$companyId}";
        $out[] = "Usuario: ".Auth::user()->email;
        $out[] = '';

        try {
            $orders = Order::query()
                ->with(['customer:id,name', 'priceList:id,name', 'seller:id,name'])
                ->limit(10)
                ->get();
            $out[] = "✓ Pedidos cargados: ".$orders->count();
            foreach ($orders as $o) {
                $out[] = "  [{$o->id}] {$o->fullNumber()} status={$o->status} customer=".
                    ($o->customer ? $o->customer->name : 'NULL').
                    " total={$o->total}";
            }
        } catch (\Throwable $e) {
            $out[] = "✗ EXCEPCION: ".get_class($e).': '.$e->getMessage();
            $out[] = "En: ".$e->getFile().':'.$e->getLine();
            $trace = explode("\n", $e->getTraceAsString());
            foreach (array_slice($trace, 0, 15) as $l) $out[] = $l;
        }

        return response(implode("\n", $out), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function show(int $order): Response
    {
        abort_unless(Auth::check(), 403);
        $companyId = (int) Auth::user()->company_id;

        $out = [];
        $out[] = "=== DEBUG PEDIDO {$order} ===";
        $out[] = "Empresa activa: {$companyId}";
        $out[] = "Usuario: ".Auth::user()->email;
        $out[] = '';

        try {
            $record = Order::query()
                ->with([
                    'items.product',
                    'customer',
                    'priceList',
                    'seller',
                    'deliveries.items',
                    'deliveries.deliveredBy',
                    'payments.createdBy',
                    'emailLogs.sentBy',
                ])
                ->where('id', $order)
                ->firstOrFail();

            $out[] = "✓ Pedido cargado: {$record->fullNumber()}";
            $out[] = "  company_id: {$record->company_id}";
            $out[] = "  status: {$record->status}";
            $out[] = "  delivery_status: {$record->delivery_status}";
            $out[] = "  payment_status: {$record->payment_status}";
            $out[] = "  total: {$record->total}";
            $out[] = "  customer: ".($record->customer ? $record->customer->name.' (id '.$record->customer->id.')' : 'NULL !!!');
            $out[] = "  priceList: ".($record->priceList ? $record->priceList->name : 'NULL');
            $out[] = "  seller: ".($record->seller ? $record->seller->email : 'NULL');
            $out[] = "  items: ".$record->items->count();
            foreach ($record->items as $i => $it) {
                $out[] = "    #{$i} line={$it->line_number} qty={$it->quantity_ordered} product=".($it->product ? $it->product->code : 'NULL !!!');
            }
            $out[] = '';
            $out[] = "Probando pendingQuantity() en cada item:";
            foreach ($record->items as $i => $it) {
                try {
                    $pending = $it->pendingQuantity();
                    $out[] = "  #{$i} pending={$pending}";
                } catch (\Throwable $e) {
                    $out[] = "  #{$i} ✗ EXCEPCION: ".$e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $out[] = '';
            $out[] = "✗ EXCEPCION AL CARGAR:";
            $out[] = get_class($e).': '.$e->getMessage();
            $out[] = "En: ".$e->getFile().':'.$e->getLine();
            $out[] = '';
            $out[] = "STACK TRACE (primeras 15 lineas):";
            $trace = explode("\n", $e->getTraceAsString());
            foreach (array_slice($trace, 0, 15) as $l) {
                $out[] = $l;
            }
        }

        return response(implode("\n", $out), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
