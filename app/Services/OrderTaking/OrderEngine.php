<?php

namespace App\Services\OrderTaking;

use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\DeliveryItem;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\OrderTaking\Payment;
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
     * Recalcula subtotal/tax_total/total del pedido desde los items.
     */
    public function recomputeTotals(Order $order): Order
    {
        $order->loadMissing('items');
        $subtotal = (float) $order->items->sum('subtotal');
        $taxTotal = (float) $order->items->sum('tax_amount');
        $total = (float) $order->items->sum('total');

        $order->update([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'total' => round($total, 2),
            'balance' => round($total - (float) $order->paid_amount, 2),
        ]);

        return $order->refresh();
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
     * Registra un abono al pedido y actualiza paid_amount + payment_status.
     */
    public function registerPayment(Order $order, array $payload): Payment
    {
        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('El monto del abono debe ser mayor a 0.');
        }
        if ($amount > (float) $order->balance + 0.01) {
            throw new RuntimeException(
                "Monto excede el saldo pendiente (\${$order->balance})."
            );
        }

        return DB::transaction(function () use ($order, $payload, $amount) {
            $payment = Payment::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
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
        $order->loadMissing('items');
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
     */
    public function refreshPaymentStatus(Order $order): void
    {
        $paid = (float) Payment::query()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->id)
            ->sum('amount');

        $balance = round((float) $order->total - $paid, 2);
        $status = match (true) {
            $paid <= 0 => 'pendiente',
            $paid + 0.01 < (float) $order->total => 'parcial',
            default => 'pagado',
        };

        $order->update([
            'paid_amount' => round($paid, 2),
            'balance' => max(0, $balance),
            'payment_status' => $status,
        ]);
    }
}
