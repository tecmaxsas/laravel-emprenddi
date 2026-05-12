<?php

namespace App\Services\Restaurant;

use App\Models\Company;
use App\Models\Product;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Printer;
use App\Models\Restaurant\Table;
use App\Models\Tax;
use App\Support\CashSessionGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de órdenes de restaurante:
 *  - open(): abre cuenta sobre mesa (o delivery/takeaway), exige caja
 *  - addItem(): agrega producto con tax + ruteo a impresora
 *  - sendToKitchen(): genera KitchenTicket por impresora y marca items
 *    como 'sent' (impresión física la hace Iter 21d con ESC/POS)
 *  - markPreparing / markReady / markServed: transiciones de items
 *  - cancel(): anula la orden completa y libera la mesa
 */
class RestaurantOrderEngine
{
    public function __construct(
        protected RestaurantOrderNumberer $numberer,
        protected KitchenTicketPrinter $ticketPrinter,
    ) {}

    /**
     * Abre una orden sobre una mesa. Falla si la mesa ya tiene una
     * orden activa o si el operador no tiene caja abierta.
     */
    public function openTableOrder(Table $table, ?int $serverUserId = null, int $guests = 1): Order
    {
        if ($table->activeOrder()) {
            throw new RuntimeException('Esta mesa ya tiene una orden abierta.');
        }
        if (! $table->active) {
            throw new RuntimeException('Esta mesa está inactiva.');
        }

        $session = CashSessionGate::requireOpenSession();
        $company = Company::find($table->company_id);

        return DB::transaction(function () use ($table, $serverUserId, $guests, $session, $company) {
            $order = Order::create([
                'company_id' => $table->company_id,
                'location_id' => $table->location_id,
                'cash_register_session_id' => $session->id,
                'table_id' => $table->id,
                'zone_id' => $table->zone_id,
                'is_delivery' => false,
                'is_takeaway' => false,
                'server_user_id' => $serverUserId ?? Auth::id(),
                'prefix' => 'ORD',
                'number' => $this->numberer->next($company, 'ORD'),
                'guests' => max(1, $guests),
                'status' => Order::STATUS_OPEN,
                'opened_at' => now(),
                'created_by_user_id' => Auth::id(),
            ]);

            $table->update(['status' => 'occupied']);

            return $order;
        });
    }

    /**
     * Agrega un item a la orden. Calcula subtotal, IVA y total.
     * Determina a qué impresora debe llegar según category_id del
     * producto (config en restaurant_printers.category_ids).
     */
    public function addItem(
        Order $order,
        Product $product,
        float $quantity = 1.0,
        ?string $note = null,
        array $modifiers = [],
        int $course = 1,
        ?string $splitTab = null,
    ): OrderItem {
        if (! $order->isOpen()) {
            throw new RuntimeException('No se pueden agregar items a una orden cerrada.');
        }
        if ($quantity <= 0) {
            throw new RuntimeException('La cantidad debe ser mayor a 0.');
        }

        $tax = $product->default_sale_tax_id
            ? Tax::find($product->default_sale_tax_id)
            : null;
        $taxRate = $tax ? (float) $tax->rate : 0.0;

        // priceForLocation() respeta override por sede; cae a default_sale_price.
        $location = $order->location_id ? \App\Models\Location::find($order->location_id) : null;
        $unitPrice = (float) $product->priceForLocation($location);

        // Suma de deltas de modificadores (por unidad). El POS pasa cada modifier
        // como ['group_name'=>..,'name'=>..,'price_delta'=>..]. Snapshot al jsonb.
        $modifierDeltaPerUnit = 0.0;
        $modifierSnapshot = [];
        foreach ($modifiers as $mod) {
            $delta = (float) ($mod['price_delta'] ?? 0);
            $modifierDeltaPerUnit += $delta;
            $modifierSnapshot[] = [
                'group_id' => $mod['group_id'] ?? null,
                'group_name' => $mod['group_name'] ?? null,
                'modifier_id' => $mod['modifier_id'] ?? null,
                'name' => $mod['name'] ?? (string) $mod,
                'price_delta' => round($delta, 2),
            ];
        }
        $modifierTotal = round($modifierDeltaPerUnit * $quantity, 2);

        $subtotal = round($quantity * $unitPrice + $modifierTotal, 2);
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total = round($subtotal + $taxAmount, 2);

        $lineNumber = ((int) $order->items()->max('line_number')) + 1;

        // Determinar impresora destino (la primera que maneje la categoría).
        $printerId = null;
        if ($product->category_id) {
            $printer = Printer::query()
                ->where('company_id', $order->company_id)
                ->where('location_id', $order->location_id)
                ->where('active', true)
                ->where('connection_type', '!=', 'browser')
                ->get()
                ->first(fn (Printer $p) => $p->handlesCategory((int) $product->category_id));
            $printerId = $printer?->id;
        }

        $item = OrderItem::create([
            'restaurant_order_id' => $order->id,
            'line_number' => $lineNumber,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'modifier_total' => $modifierTotal,
            'tax_id' => $tax?->id,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'modifiers' => $modifierSnapshot ?: null,
            'item_note' => $note,
            'kitchen_status' => OrderItem::KS_PENDING,
            'course' => max(1, $course),
            'split_tab' => $splitTab,
            'printed_to_printer_id' => $printerId,
        ]);

        $this->recalculateTotals($order);

        return $item;
    }

    public function updateItemQuantity(OrderItem $item, float $quantity): void
    {
        if ($quantity <= 0) {
            $this->cancelItem($item, 'cantidad cero');
            return;
        }
        if (in_array($item->kitchen_status, [OrderItem::KS_READY, OrderItem::KS_SERVED], true)) {
            throw new RuntimeException('No se puede cambiar la cantidad de un item ya entregado.');
        }

        $unitPrice = (float) $item->unit_price;
        // modifier_total es per-linea, escala con la cantidad nueva.
        $modifierDeltaPerUnit = $item->quantity > 0 ? (float) $item->modifier_total / (float) $item->quantity : 0;
        $modifierTotal = round($modifierDeltaPerUnit * $quantity, 2);
        $subtotal = round($quantity * $unitPrice + $modifierTotal, 2);
        $taxAmount = round($subtotal * (float) $item->tax_rate / 100, 2);

        $item->update([
            'quantity' => $quantity,
            'subtotal' => $subtotal,
            'modifier_total' => $modifierTotal,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
        ]);

        $this->recalculateTotals($item->order);
    }

    public function cancelItem(OrderItem $item, ?string $reason = null): void
    {
        $item->update([
            'kitchen_status' => OrderItem::KS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);
        $this->recalculateTotals($item->order);
    }

    public function recalculateTotals(Order $order): void
    {
        $order->loadMissing('items');
        $activeItems = $order->items->reject(fn ($i) => $i->kitchen_status === OrderItem::KS_CANCELLED);

        $subtotal = (float) $activeItems->sum('subtotal');
        $discountTotal = (float) $activeItems->sum('discount_amount');
        $taxTotal = (float) $activeItems->sum('tax_amount');
        $total = round($subtotal - $discountTotal + $taxTotal + (float) $order->tip_amount + (float) $order->delivery_fee, 2);

        $order->update([
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'total' => $total,
        ]);
    }

    /**
     * Manda a cocina los items 'pending'. Agrupa por impresora y crea
     * KitchenTicket (uno por impresora). En Iter 21d el ticket dispara
     * la impresión ESC/POS real; por ahora solo registra el evento.
     *
     * Devuelve los tickets creados.
     */
    public function sendPendingToKitchen(Order $order): array
    {
        $order->loadMissing('items');
        $pending = $order->items->where('kitchen_status', OrderItem::KS_PENDING);

        if ($pending->isEmpty()) {
            throw new RuntimeException('No hay items pendientes para enviar a cocina.');
        }

        return DB::transaction(function () use ($order, $pending) {
            $nextBatch = ((int) $order->kitchenTickets()->max('batch_number')) + 1;

            $byPrinter = $pending->groupBy('printed_to_printer_id');
            $tickets = [];

            foreach ($byPrinter as $printerId => $items) {
                // Items sin impresora asignada: igual los marcamos enviados
                // pero NO generamos ticket (no hay destino).
                if (! $printerId) {
                    foreach ($items as $it) {
                        $it->update([
                            'kitchen_status' => OrderItem::KS_SENT,
                            'sent_to_kitchen_at' => now(),
                            'kot_batch' => $nextBatch,
                        ]);
                    }
                    continue;
                }

                $snapshot = $items->map(fn (OrderItem $i) => [
                    'line_number' => $i->line_number,
                    'description' => $i->description,
                    'quantity' => (float) $i->quantity,
                    'modifiers' => $i->modifiers,
                    'note' => $i->item_note,
                    'course' => (int) $i->course,
                    'split_tab' => $i->split_tab,
                ])->values()->all();

                $ticket = KitchenTicket::create([
                    'restaurant_order_id' => $order->id,
                    'restaurant_printer_id' => $printerId,
                    'batch_number' => $nextBatch,
                    'items_snapshot' => $snapshot,
                    'status' => 'printed',  // Iter 21d: aquí se dispara el ESC/POS real
                    'printed_by_user_id' => Auth::id(),
                    'printed_at' => now(),
                ]);

                foreach ($items as $it) {
                    $it->update([
                        'kitchen_status' => OrderItem::KS_SENT,
                        'sent_to_kitchen_at' => now(),
                        'kot_batch' => $nextBatch,
                    ]);
                }

                $tickets[] = $ticket;
            }

            if ($order->status === Order::STATUS_OPEN) {
                $order->update(['status' => Order::STATUS_IN_KITCHEN]);
            }

            // Disparar impresión REAL (network/cups). Si falla, el ticket
            // queda status='failed' con error_message pero la orden ya
            // está enviada — el cocinero puede ver desde el KDS.
            foreach ($tickets as $ticket) {
                $this->ticketPrinter->print($ticket);
            }

            return $tickets;
        });
    }

    /**
     * Cierra la orden tras pagar. Marca status=closed, posted_at,
     * libera la mesa. Iter 21e: aquí debería generarse SaleInvoice
     * y aplicar pagos. Por ahora solo finaliza la orden y libera
     * la mesa para que se pueda usar de nuevo.
     */
    public function close(Order $order, ?string $notes = null): Order
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('Solo se pueden cerrar órdenes abiertas.');
        }

        return DB::transaction(function () use ($order, $notes) {
            $order->update([
                'status' => Order::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by_user_id' => Auth::id(),
                'notes' => $notes
                    ? trim(($order->notes ?? '')."\n".$notes)
                    : $order->notes,
            ]);

            if ($order->table) {
                $order->table->update(['status' => 'free']);
            }

            return $order->fresh();
        });
    }

    public function markItemPreparing(OrderItem $item): void
    {
        $item->update([
            'kitchen_status' => OrderItem::KS_PREPARING,
            'preparing_at' => now(),
        ]);
    }

    public function markItemReady(OrderItem $item): void
    {
        $item->update([
            'kitchen_status' => OrderItem::KS_READY,
            'ready_at' => now(),
        ]);
    }

    public function markItemServed(OrderItem $item): void
    {
        $item->update([
            'kitchen_status' => OrderItem::KS_SERVED,
            'served_at' => now(),
        ]);

        // Si TODOS los items activos están servidos, la orden pasa a 'served'
        $order = $item->order;
        $order->loadMissing('items');
        $hasIncomplete = $order->items
            ->reject(fn ($i) => $i->kitchen_status === OrderItem::KS_CANCELLED)
            ->contains(fn ($i) => $i->kitchen_status !== OrderItem::KS_SERVED);

        if (! $hasIncomplete && $order->status !== Order::STATUS_BILLING) {
            $order->update(['status' => Order::STATUS_SERVED]);
        }
    }

    public function cancel(Order $order, ?string $reason = null): Order
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('Solo se pueden anular órdenes abiertas.');
        }

        return DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'closed_by_user_id' => Auth::id(),
                'notes' => trim(($order->notes ?? '')."\nAnulada: ".($reason ?? 'sin motivo')),
            ]);

            if ($order->table) {
                $order->table->update(['status' => 'free']);
            }

            return $order->fresh();
        });
    }
}
