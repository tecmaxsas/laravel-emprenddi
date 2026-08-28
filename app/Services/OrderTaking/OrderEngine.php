<?php

namespace App\Services\OrderTaking;

use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\DeliveryItem;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\OrderTaking\OrderRetention;
use App\Models\OrderTaking\Payment;
use App\Models\Tax;
use App\Models\ThirdParty;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor unico del modulo Toma pedidos. Centraliza:
 *   - reserva de consecutivo interno (PED-XXXXXX)
 *   - recomputo de totales de la cabecera desde items
 *   - registro de despachos y actualizacion de delivery_status
 *   - registro de pagos y actualizacion de payment_status/balance
 *
 * No genera factura de venta — el pedido es documento operativo aparte.
 */
class OrderEngine
{
    /**
     * Reserva el siguiente numero interno para (company_id, prefix). Auto-sanea
     * si por alguna razon existen numeros ya usados que superan el last known.
     * Mismo patron defensivo que DocumentNumberer.
     */
    public function reserveNumber(int $companyId, string $prefix = 'PED'): int
    {
        return DB::transaction(function () use ($companyId, $prefix) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [
                    crc32('order_taking:'.$companyId.':'.$prefix),
                ]);
            }

            $max = Order::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('prefix', $prefix)
                ->when(! $isPg, fn ($q) => $q->lockForUpdate())
                ->max('number');

            return ((int) $max) + 1;
        });
    }

    /**
     * Recalcula subtotal/tax_total/total del pedido desde los items, y el neto
     * a pagar descontando las retenciones.
     *
     * La retencion no es un saldo por cobrar: es un anticipo de impuesto que el
     * cliente le consigna a la DIAN en nuestro nombre. Por eso el saldo se mide
     * contra net_payable y no contra total.
     */
    public function recomputeTotals(Order $order): Order
    {
        // Igual que en refreshDeliveryStatus: recargar, no confiar en lo que ya
        // estuviera cargado, porque las lineas o las retenciones pudieron
        // cambiar en esta misma peticion.
        $order->load('items', 'retentions');
        $subtotal = (float) $order->items->sum('subtotal');
        $taxTotal = (float) $order->items->sum('tax_amount');
        $total = (float) $order->items->sum('total');
        $retentionTotal = (float) $order->retentions->sum('amount');
        $netPayable = max(0, round($total - $retentionTotal, 2));

        $order->update([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'retention_total' => round($retentionTotal, 2),
            'total' => round($total, 2),
            'net_payable' => $netPayable,
            'balance' => round($netPayable - (float) $order->paid_amount, 2),
        ]);

        return $order->refresh();
    }

    /**
     * Retenciones que le corresponden a un cliente sobre una base gravable.
     *
     * Devuelve el snapshot listo para pintar en pantalla y para guardar: si
     * manana cambian la tarifa del impuesto, el pedido ya tomado no se mueve.
     *
     * @return list<array{tax_id:int,tax_code:string,tax_name:string,tax_type:string,base_amount:float,rate:float,amount:float}>
     */
    public function suggestRetentionsFor(?ThirdParty $customer, float $base): array
    {
        if (! $customer) {
            return [];
        }

        $base = max(0, round($base, 2));

        return $customer->retentionTaxes()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (Tax $tax) => [
                'tax_id' => (int) $tax->id,
                'tax_code' => (string) $tax->code,
                'tax_name' => (string) $tax->name,
                'tax_type' => (string) $tax->type,
                'base_amount' => $base,
                'rate' => (float) $tax->rate,
                'amount' => round($base * ((float) $tax->rate / 100), 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Reemplaza las retenciones del pedido por las recibidas. No recomputa
     * totales: eso lo hace recomputeTotals(), que es quien manda en la cabecera.
     */
    public function syncRetentions(Order $order, array $rows): void
    {
        DB::transaction(function () use ($order, $rows) {
            OrderRetention::query()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->id)
                ->delete();

            foreach ($rows as $row) {
                $amount = round((float) ($row['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                OrderRetention::create([
                    'company_id' => $order->company_id,
                    'order_id' => $order->id,
                    'tax_id' => (int) $row['tax_id'],
                    'tax_code' => (string) ($row['tax_code'] ?? ''),
                    'tax_name' => (string) ($row['tax_name'] ?? ''),
                    'tax_type' => (string) ($row['tax_type'] ?? ''),
                    'base_amount' => round((float) ($row['base_amount'] ?? 0), 2),
                    'rate' => (float) ($row['rate'] ?? 0),
                    'amount' => $amount,
                ]);
            }
        });
    }

    /**
     * Registra un despacho parcial o total. Actualiza quantity_delivered en
     * cada order_item y recomputa delivery_status del pedido.
     *
     * @param array $items array de ['order_item_id' => int, 'quantity' => float]
     */
    public function registerDelivery(Order $order, array $items, ?string $deliveryNumber = null, ?string $notes = null, ?string $deliveryDate = null): Delivery
    {
        if (empty($items)) {
            throw new RuntimeException('Selecciona al menos una linea a despachar.');
        }

        return DB::transaction(function () use ($order, $items, $deliveryNumber, $notes, $deliveryDate) {
            $delivery = Delivery::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'delivery_number' => $deliveryNumber,
                'delivery_date' => $deliveryDate ?? now()->toDateString(),
                'delivered_by_user_id' => Auth::id(),
                'notes' => $notes,
            ]);

            foreach ($items as $row) {
                $qty = (float) ($row['quantity'] ?? 0);
                if ($qty <= 0) continue;

                $orderItem = OrderItem::query()
                    ->where('company_id', $order->company_id)
                    ->where('order_id', $order->id)
                    ->find($row['order_item_id']);
                if (! $orderItem) {
                    throw new RuntimeException('Linea del pedido no encontrada.');
                }

                $pending = $orderItem->pendingQuantity();
                if ($qty > $pending + 0.0001) {
                    throw new RuntimeException(
                        "No puedes despachar {$qty} de {$orderItem->description} — pendiente: {$pending}."
                    );
                }

                DeliveryItem::create([
                    'company_id' => $order->company_id,
                    'delivery_id' => $delivery->id,
                    'order_item_id' => $orderItem->id,
                    'quantity_delivered' => $qty,
                ]);

                $orderItem->update([
                    'quantity_delivered' => (float) $orderItem->quantity_delivered + $qty,
                ]);
            }

            $this->refreshDeliveryStatus($order);

            return $delivery->fresh(['items']);
        });
    }

    /**
     * Registra un abono contra un despacho y actualiza paid_amount +
     * payment_status del pedido.
     *
     * El abono cuelga del despacho, no del pedido: asi se sabe que entrega esta
     * pagando el cliente. El tope sigue siendo el saldo del pedido y no el
     * valor del despacho, porque es normal que un solo pago cubra mas de una
     * entrega o que abonen de mas sobre una.
     */
    public function registerPayment(Delivery $delivery, array $payload): Payment
    {
        $order = $delivery->order;

        if (! $order) {
            throw new RuntimeException('El despacho no tiene pedido asociado.');
        }

        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('El monto del abono debe ser mayor a 0.');
        }
        if ($amount > (float) $order->balance + 0.01) {
            throw new RuntimeException(
                "Monto excede el saldo pendiente (\${$order->balance})."
            );
        }

        return DB::transaction(function () use ($order, $delivery, $payload, $amount) {
            $payment = Payment::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'delivery_id' => $delivery->id,
                'payment_date' => $payload['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'account_id' => $payload['account_id'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->refreshPaymentStatus($order);

            return $payment;
        });
    }

    /**
     * Recalcula delivery_status del pedido:
     *   pending  = ningun item entregado
     *   partial  = algun item con delivered > 0 pero total < ordered
     *   delivered = todos los items delivered >= ordered
     */
    public function refreshDeliveryStatus(Order $order): void
    {
        // load() y no loadMissing(): se llama justo despues de mover las
        // cantidades, y si las lineas ya venian cargadas loadMissing devuelve
        // la coleccion vieja y el estado se calcula con datos de antes del
        // despacho.
        $order->load('items');
        $totalOrdered = (float) $order->items->sum('quantity_ordered');
        $totalDelivered = (float) $order->items->sum('quantity_delivered');

        $deliveryStatus = match (true) {
            $totalOrdered <= 0 => 'pending',
            $totalDelivered <= 0 => 'pending',
            $totalDelivered + 0.0001 >= $totalOrdered => 'delivered',
            default => 'partial',
        };

        $newStatus = $order->status;
        if ($deliveryStatus === 'delivered' && $newStatus !== Order::STATUS_CANCELLED) {
            $newStatus = Order::STATUS_FULLY_DELIVERED;
        } elseif ($deliveryStatus === 'partial' && $newStatus !== Order::STATUS_CANCELLED) {
            $newStatus = Order::STATUS_PARTIAL_DELIVERED;
        }

        $order->update([
            'delivery_status' => $deliveryStatus,
            'status' => $newStatus,
        ]);
    }

    /**
     * Recalcula payment_status/balance desde la suma de payments.
     *
     * Se mide contra net_payable (total menos retenciones), que es lo que el
     * cliente realmente debe poner de su bolsillo.
     */
    public function refreshPaymentStatus(Order $order): void
    {
        $paid = (float) Payment::query()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->id)
            ->sum('amount');

        $payable = (float) $order->net_payable;
        $balance = round($payable - $paid, 2);
        $status = match (true) {
            $paid <= 0 => 'pendiente',
            $paid + 0.01 < $payable => 'parcial',
            default => 'pagado',
        };

        $order->update([
            'paid_amount' => round($paid, 2),
            'balance' => max(0, $balance),
            'payment_status' => $status,
        ]);
    }
}
