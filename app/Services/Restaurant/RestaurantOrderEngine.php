<?php

namespace App\Services\Restaurant;

use App\Models\Company;
use App\Models\Product;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Printer;
use App\Models\Restaurant\Table;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Services\Sales\SaleInvoiceEngine;
use App\Services\Sales\SaleInvoiceNumberer;
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
     * Abre una orden "para llevar" sin mesa asignada. Util para pickup,
     * delivery improvisado o cliente que no se va a sentar.
     */
    public function openTakeawayOrder(
        \App\Models\Location $location,
        int $guests = 1,
        ?string $customerName = null,
    ): Order {
        if (! $location->active) {
            throw new RuntimeException('La sede está inactiva.');
        }

        $session = CashSessionGate::requireOpenSession();
        $company = Company::find($location->company_id);

        return DB::transaction(function () use ($location, $guests, $session, $company, $customerName) {
            return Order::create([
                'company_id' => $location->company_id,
                'location_id' => $location->id,
                'cash_register_session_id' => $session->id,
                'table_id' => null,
                'zone_id' => null,
                'is_delivery' => false,
                'is_takeaway' => true,
                'server_user_id' => Auth::id(),
                'prefix' => 'ORD',
                'number' => $this->numberer->next($company, 'ORD'),
                'guests' => max(1, $guests),
                'status' => Order::STATUS_OPEN,
                'opened_at' => now(),
                'created_by_user_id' => Auth::id(),
                'delivery_metadata' => $customerName ? ['customer_name' => $customerName] : null,
                'notes' => $customerName ? "Para llevar — Cliente: {$customerName}" : 'Para llevar',
            ]);
        });
    }

    /**
     * Abre una orden de domicilio (delivery). Sin mesa. Guarda direccion,
     * telefono y notas del cliente en delivery_metadata. Estado inicial
     * del delivery: 'preparing'.
     */
    public function openDeliveryOrder(
        \App\Models\Location $location,
        string $customerName,
        string $address,
        ?string $phone = null,
        ?string $addressNotes = null,
        float $deliveryFee = 0,
        int $guests = 1,
    ): Order {
        if (! $location->active) {
            throw new RuntimeException('La sede está inactiva.');
        }
        if (trim($customerName) === '') {
            throw new RuntimeException('Nombre del cliente requerido.');
        }
        if (trim($address) === '') {
            throw new RuntimeException('Dirección de entrega requerida.');
        }

        $session = CashSessionGate::requireOpenSession();
        $company = Company::find($location->company_id);

        $metadata = [
            'customer_name' => $customerName,
            'customer_phone' => $phone ?: null,
            'address' => $address,
            'address_notes' => $addressNotes ?: null,
            'driver_id' => null,
            'delivery_status' => Order::DELIVERY_PREPARING,
            'dispatched_at' => null,
            'delivered_at' => null,
        ];

        return DB::transaction(function () use ($location, $guests, $session, $company, $metadata, $deliveryFee, $customerName) {
            return Order::create([
                'company_id' => $location->company_id,
                'location_id' => $location->id,
                'cash_register_session_id' => $session->id,
                'table_id' => null,
                'zone_id' => null,
                'is_delivery' => true,
                'is_takeaway' => false,
                'server_user_id' => Auth::id(),
                'prefix' => 'ORD',
                'number' => $this->numberer->next($company, 'ORD'),
                'tracking_token' => \Illuminate\Support\Str::random(32),
                'guests' => max(1, $guests),
                'status' => Order::STATUS_OPEN,
                'opened_at' => now(),
                'delivery_fee' => max(0, round($deliveryFee, 2)),
                'created_by_user_id' => Auth::id(),
                'delivery_metadata' => $metadata,
                'notes' => "Domicilio — {$customerName}",
            ]);
        });
    }

    /**
     * Asigna o quita un driver al pedido de delivery. Pasar null para
     * desasignar. Actualiza delivery_metadata.driver_id.
     */
    public function assignDeliveryDriver(Order $order, ?\App\Models\Restaurant\Driver $driver): Order
    {
        if (! $order->is_delivery) {
            throw new RuntimeException('La orden no es de domicilio.');
        }
        $metadata = $order->delivery_metadata ?? [];
        $metadata['driver_id'] = $driver?->id;
        $metadata['driver_name'] = $driver?->name;
        $order->update(['delivery_metadata' => $metadata]);
        return $order->fresh();
    }

    /**
     * Cambia el sub-estado del delivery. Estados validos:
     *  preparing -> ready -> on_the_way -> delivered
     * Marca timestamps relevantes (dispatched_at, delivered_at).
     */
    public function setDeliveryStatus(Order $order, string $status): Order
    {
        if (! $order->is_delivery) {
            throw new RuntimeException('La orden no es de domicilio.');
        }
        if (! array_key_exists($status, Order::DELIVERY_STATUSES)) {
            throw new RuntimeException('Estado de delivery inválido.');
        }

        $metadata = $order->delivery_metadata ?? [];
        $metadata['delivery_status'] = $status;

        // Captura timestamp en cada transicion clave para reportes.
        if ($status === Order::DELIVERY_READY && empty($metadata['ready_at'])) {
            $metadata['ready_at'] = now()->toDateTimeString();
        }
        if ($status === Order::DELIVERY_ON_THE_WAY && empty($metadata['dispatched_at'])) {
            $metadata['dispatched_at'] = now()->toDateTimeString();
        }
        if ($status === Order::DELIVERY_DELIVERED && empty($metadata['delivered_at'])) {
            $metadata['delivered_at'] = now()->toDateTimeString();
        }

        $order->update(['delivery_metadata' => $metadata]);
        return $order->fresh();
    }

    /**
     * Actualiza el delivery_fee de la orden y recalcula totales.
     */
    public function setDeliveryFee(Order $order, float $fee): Order
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('Solo se puede ajustar el delivery fee en órdenes abiertas.');
        }
        $order->update(['delivery_fee' => max(0, round($fee, 2))]);
        $this->recalculateTotals($order);
        return $order->fresh();
    }

    /**
     * Cambia el modo de servicio de una orden (comer aquí / para llevar).
     * Afecta cómo se imprime la comanda y la tasa de IVA: al cambiar de
     * modo se recalculan los impuestos de todos los items vivos (IVA
     * diferencial — ver resolveTaxForMode).
     */
    public function setServiceMode(Order $order, string $mode): Order
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('Solo se puede cambiar el modo en órdenes abiertas.');
        }
        if (! in_array($mode, ['dine_in', 'takeaway'], true)) {
            throw new RuntimeException('Modo de servicio inválido.');
        }

        $order->update([
            'is_takeaway' => $mode === 'takeaway',
            'is_delivery' => false,
        ]);

        $this->reapplyTaxesForMode($order->fresh());

        return $order->fresh();
    }

    /**
     * Devuelve el impuesto a aplicar según el modo de servicio de la orden.
     * Si la orden es para llevar / delivery y el impuesto base tiene un
     * takeaway_tax_id configurado, retorna esa alternativa.
     */
    public function resolveTaxForMode(?Tax $baseTax, Order $order): ?Tax
    {
        if (! $baseTax) return null;

        $isTakeawayMode = $order->is_takeaway || $order->is_delivery;
        if ($isTakeawayMode && $baseTax->takeaway_tax_id) {
            return Tax::find($baseTax->takeaway_tax_id) ?? $baseTax;
        }

        return $baseTax;
    }

    /**
     * Recalcula los impuestos de todos los items vivos de la orden según
     * el modo de servicio actual. Se dispara al cambiar comer aquí ↔ llevar.
     * Re-deriva el impuesto base desde el producto de cada item.
     */
    public function reapplyTaxesForMode(Order $order): void
    {
        $order->load('items');

        foreach ($order->items as $item) {
            if ($item->kitchen_status === OrderItem::KS_CANCELLED) continue;
            if (! $item->product_id) continue;

            $product = Product::find($item->product_id);
            if (! $product) continue;

            $baseTax = $product->default_sale_tax_id ? Tax::find($product->default_sale_tax_id) : null;
            $tax = $this->resolveTaxForMode($baseTax, $order);
            $rate = $tax ? (float) $tax->rate : 0.0;

            // subtotal no cambia (ya incluye modificadores); solo recalcula IVA.
            $taxAmount = round((float) $item->subtotal * $rate / 100, 2);

            $item->update([
                'tax_id' => $tax?->id,
                'tax_rate' => $rate,
                'tax_amount' => $taxAmount,
                'total' => round((float) $item->subtotal + $taxAmount, 2),
            ]);
        }

        $this->recalculateTotals($order);
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
        // IVA diferencial: si la orden es para llevar/delivery y el impuesto
        // tiene una alternativa configurada, se usa esa.
        $tax = $this->resolveTaxForMode($tax, $order);
        $taxRate = $tax ? (float) $tax->rate : 0.0;

        // priceForLocation() respeta override por sede; cae a default_sale_price.
        $location = $order->location_id ? \App\Models\Location::find($order->location_id) : null;
        $rawPrice = (float) $product->priceForLocation($location);

        // Si el precio del producto incluye el impuesto, desnormalizar: el
        // unit_price guardado siempre es BASE (antes de IVA) para mantener
        // consistencia con el resto del modelo contable.
        if ($product->sale_price_includes_tax && $taxRate > 0) {
            $unitPrice = round($rawPrice / (1 + $taxRate / 100), 2);
        } else {
            $unitPrice = $rawPrice;
        }

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
        // Incluye impresoras 'browser' (QZ Tray) — el ruteo es agnóstico al
        // tipo de conexión; la impresión real la resuelve KitchenTicketPrinter.
        $printerId = null;
        if ($product->category_id) {
            $printer = Printer::query()
                ->where('company_id', $order->company_id)
                ->where('location_id', $order->location_id)
                ->where('active', true)
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

    /**
     * Agrega un item "mitad y mitad": dos productos diferentes en una sola
     * linea (típicamente pizza con dos sabores). Precio = max(precio A, B)
     * — convencion CO de cobrar la mitad mas cara.
     *
     * Ambas mitades deben pertenecer a la misma categoria para evitar
     * combinaciones absurdas (pizza + sopa). Tax y impresora se toman del
     * producto mas caro para reportes y ruteo.
     */
    public function addHalfAndHalf(
        Order $order,
        Product $a,
        Product $b,
        int $course = 1,
        ?string $note = null,
    ): OrderItem {
        if (! $order->isOpen()) {
            throw new RuntimeException('No se pueden agregar items a una orden cerrada.');
        }
        if ($a->id === $b->id) {
            throw new RuntimeException('Las dos mitades deben ser productos distintos.');
        }
        if ($a->category_id !== $b->category_id) {
            throw new RuntimeException('Las dos mitades deben ser de la misma categoria.');
        }

        $location = $order->location_id ? \App\Models\Location::find($order->location_id) : null;

        // Normalizar precios a "base" (sin IVA) si el flag dice que el precio
        // ya incluia el impuesto. La tasa respeta el modo (para llevar).
        $taxAResolved = $this->resolveTaxForMode(
            $a->default_sale_tax_id ? Tax::find($a->default_sale_tax_id) : null,
            $order,
        );
        $taxBResolved = $this->resolveTaxForMode(
            $b->default_sale_tax_id ? Tax::find($b->default_sale_tax_id) : null,
            $order,
        );
        $taxARate = $taxAResolved ? (float) $taxAResolved->rate : 0;
        $taxBRate = $taxBResolved ? (float) $taxBResolved->rate : 0;

        $rawA = (float) $a->priceForLocation($location);
        $rawB = (float) $b->priceForLocation($location);

        $priceA = ($a->sale_price_includes_tax && $taxARate > 0)
            ? round($rawA / (1 + $taxARate / 100), 2)
            : $rawA;
        $priceB = ($b->sale_price_includes_tax && $taxBRate > 0)
            ? round($rawB / (1 + $taxBRate / 100), 2)
            : $rawB;

        $unitPrice = max($priceA, $priceB);

        // Producto "principal" = el mas caro (atribucion en reportes + tax + printer)
        $mainProduct = $priceA >= $priceB ? $a : $b;

        $tax = $this->resolveTaxForMode(
            $mainProduct->default_sale_tax_id ? Tax::find($mainProduct->default_sale_tax_id) : null,
            $order,
        );
        $taxRate = $tax ? (float) $tax->rate : 0.0;

        $quantity = 1.0;
        $subtotal = round($quantity * $unitPrice, 2);
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total = round($subtotal + $taxAmount, 2);

        $description = "1/2 {$a->name} + 1/2 {$b->name}";

        $composite = [
            ['product_id' => $a->id, 'name' => $a->name, 'price' => round($priceA, 2), 'ratio' => 0.5],
            ['product_id' => $b->id, 'name' => $b->name, 'price' => round($priceB, 2), 'ratio' => 0.5],
        ];

        $lineNumber = ((int) $order->items()->max('line_number')) + 1;

        // Printer del producto principal (su categoria). Incluye browser/QZ Tray.
        $printerId = null;
        if ($mainProduct->category_id) {
            $printer = Printer::query()
                ->where('company_id', $order->company_id)
                ->where('location_id', $order->location_id)
                ->where('active', true)
                ->get()
                ->first(fn (Printer $p) => $p->handlesCategory((int) $mainProduct->category_id));
            $printerId = $printer?->id;
        }

        $item = OrderItem::create([
            'restaurant_order_id' => $order->id,
            'line_number' => $lineNumber,
            'product_id' => $mainProduct->id,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'modifier_total' => 0,
            'tax_id' => $tax?->id,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'modifiers' => null,
            'composite_items' => $composite,
            'item_note' => $note,
            'kitchen_status' => OrderItem::KS_PENDING,
            'course' => max(1, $course),
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
        // load(), no loadMissing(): si la coleccion ya estaba hidratada en memoria
        // antes del addItem/cancel/update, loadMissing la deja stale y el sum() del
        // total ignora el item recien creado.
        $order->load('items');
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
     * KitchenTicket (uno por impresora). Si se pasa $courseFilter,
     * solo envía los items de ese curso (entrada → principal → postre),
     * lo que permite secuenciar la cocina.
     *
     * Devuelve los tickets creados.
     */
    public function sendPendingToKitchen(Order $order, ?int $courseFilter = null): array
    {
        $order->load('items');
        $pending = $order->items->where('kitchen_status', OrderItem::KS_PENDING);

        if ($courseFilter !== null) {
            $pending = $pending->where('course', $courseFilter);
        }

        if ($pending->isEmpty()) {
            throw new RuntimeException(
                $courseFilter !== null
                    ? 'No hay items pendientes del curso '.($courseFilter).' para enviar.'
                    : 'No hay items pendientes para enviar a cocina.'
            );
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
                    'composite_items' => $i->composite_items,
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

    /**
     * Mueve una orden a otra mesa libre (misma sede). Util cuando los
     * clientes piden cambio de mesa o el mesero se equivoco al abrir.
     */
    public function transferOrder(Order $order, Table $newTable): Order
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('Solo se pueden transferir ordenes abiertas.');
        }
        if ($newTable->id === $order->table_id) {
            throw new RuntimeException('La orden ya esta en esa mesa.');
        }
        if (! $newTable->active) {
            throw new RuntimeException('La mesa destino esta inactiva.');
        }
        if ($newTable->activeOrder()) {
            throw new RuntimeException('La mesa destino ya tiene una orden abierta. Usa "Juntar mesas".');
        }
        if ($newTable->location_id !== $order->location_id) {
            throw new RuntimeException('No se puede transferir entre sedes distintas.');
        }

        return DB::transaction(function () use ($order, $newTable) {
            $oldTable = $order->table;
            $oldCode = $oldTable?->code ?? '—';

            $order->update([
                'table_id' => $newTable->id,
                'zone_id' => $newTable->zone_id,
                'notes' => trim(($order->notes ?? '')."\nTransferida: mesa {$oldCode} → mesa {$newTable->code}"),
            ]);

            if ($oldTable) {
                $oldTable->update(['status' => 'free']);
            }
            $newTable->update(['status' => 'occupied']);

            return $order->fresh();
        });
    }

    /**
     * Fusiona la orden secondary dentro de la primary. Los items pasan a
     * la primary, la secondary se cancela con nota de fusion y su mesa
     * se libera. Los tickets ya impresos del secondary quedan historicos
     * (no se reescriben — son evidencia inmutable de lo que fue a cocina).
     */
    public function mergeOrders(Order $primary, Order $secondary): Order
    {
        if (! $primary->isOpen() || ! $secondary->isOpen()) {
            throw new RuntimeException('Ambas ordenes deben estar abiertas para fusionar.');
        }
        if ($primary->id === $secondary->id) {
            throw new RuntimeException('No se puede fusionar una orden consigo misma.');
        }
        if ($primary->company_id !== $secondary->company_id) {
            throw new RuntimeException('No se puede fusionar entre empresas distintas.');
        }

        return DB::transaction(function () use ($primary, $secondary) {
            $secTable = $secondary->table;
            $secCode = $secTable?->code ?? '—';
            $secNumber = $secondary->fullNumber();

            // Mover items del secondary al primary, ajustando line_number
            $startLine = ((int) $primary->items()->max('line_number')) + 1;
            $secondary->items()->orderBy('line_number')->get()
                ->each(function (OrderItem $item) use ($primary, &$startLine) {
                    $item->update([
                        'restaurant_order_id' => $primary->id,
                        'line_number' => $startLine++,
                    ]);
                });

            // Marcar secondary como anulado con nota explicita de fusion
            // (no creamos status 'merged' nuevo para no migrar el enum)
            $secondary->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'closed_by_user_id' => Auth::id(),
                'notes' => trim(($secondary->notes ?? '')."\nFusionada en orden {$primary->fullNumber()} (mesa {$primary->table?->code})"),
            ]);

            if ($secTable) {
                $secTable->update(['status' => 'free']);
            }

            $primary->update([
                'notes' => trim(($primary->notes ?? '')."\nIncluye items fusionados de mesa {$secCode} (orden {$secNumber})"),
            ]);
            $this->recalculateTotals($primary);

            return $primary->fresh();
        });
    }

    // ============ COBRO / FACTURACION ============

    /**
     * Setea la propina de la orden. Si $percentage no es null, calcula
     * el monto sobre el subtotal y guarda ambos campos. Si solo viene
     * $amount, guarda el monto y limpia el porcentaje.
     */
    public function setTip(Order $order, ?float $percentage, ?float $amount = null): Order
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('Solo se puede ajustar propina en órdenes abiertas.');
        }

        if ($percentage !== null) {
            $pct = max(0, min(100, (float) $percentage));
            $tip = round((float) $order->subtotal * $pct / 100, 2);
            $order->update([
                'tip_percentage' => $pct,
                'tip_amount' => $tip,
            ]);
        } else {
            $order->update([
                'tip_percentage' => null,
                'tip_amount' => round((float) ($amount ?? 0), 2),
            ]);
        }

        $this->recalculateTotals($order);
        return $order->fresh();
    }

    public function setItemTab(OrderItem $item, ?string $tab): void
    {
        $clean = $tab !== null ? trim($tab) : null;
        $item->update(['split_tab' => $clean !== '' ? $clean : null]);
    }

    /**
     * Genera SaleInvoice(s) para una orden y aplica los pagos. Cierra la
     * orden y libera la mesa.
     *
     * $tabs: array de tabs a facturar, cada uno:
     *   [
     *     'label'          => 'A' | null  (null = cuenta única sin split),
     *     'item_ids'       => [1, 2, 3],
     *     'payment_method' => 'cash' | 'bank_transfer' | ...,
     *     'account_id'     => int (cuenta contable donde entra la plata),
     *     'reference'      => string|null,
     *   ]
     *
     * Si no hay split, debe venir un único tab con todos los item_ids
     * vivos (no cancelados). La propina se distribuye proporcional al
     * subtotal de cada tab y se imprime en notas de la factura — NO
     * va como linea (CO: propina voluntaria, sin IVA, no facturable).
     *
     * @return SaleInvoice[]
     */
    public function bill(Order $order, array $tabs, int $thirdPartyId): array
    {
        if (! $order->isOpen()) {
            throw new RuntimeException('Solo se pueden cobrar órdenes abiertas.');
        }
        if (empty($tabs)) {
            throw new RuntimeException('No hay tabs para facturar.');
        }

        $order->load('items');
        $activeItems = $order->items->reject(fn ($i) => $i->kitchen_status === OrderItem::KS_CANCELLED);

        if ($activeItems->isEmpty()) {
            throw new RuntimeException('La orden no tiene items para cobrar.');
        }

        // Validar que cada item_id pertenece a la orden y solo aparece en un tab
        $declaredIds = [];
        foreach ($tabs as $t) {
            foreach (($t['item_ids'] ?? []) as $id) {
                if (in_array($id, $declaredIds, true)) {
                    throw new RuntimeException("Item {$id} declarado en más de un tab.");
                }
                $declaredIds[] = (int) $id;
            }
        }
        $activeIds = $activeItems->pluck('id')->all();
        $missing = array_diff($activeIds, $declaredIds);
        if ($missing) {
            throw new RuntimeException('Hay items sin asignar a un tab: '.implode(', ', $missing));
        }
        $extra = array_diff($declaredIds, $activeIds);
        if ($extra) {
            throw new RuntimeException('Hay items que no pertenecen a la orden: '.implode(', ', $extra));
        }

        $orderSubtotal = (float) $activeItems->sum('subtotal');
        $orderTip = (float) $order->tip_amount;

        $invoiceEngine = app(SaleInvoiceEngine::class);
        $numberer = app(SaleInvoiceNumberer::class);
        $company = Company::find($order->company_id);

        // Jobs de impresión de recibo — se ejecutan DESPUÉS del commit para
        // no demorar la transacción si la impresora está lenta/apagada.
        $receiptJobs = [];

        $invoices = DB::transaction(function () use (
            $order, $tabs, $activeItems, $orderSubtotal, $orderTip,
            $invoiceEngine, $numberer, $company, $thirdPartyId, &$receiptJobs
        ) {
            $invoices = [];

            foreach ($tabs as $tab) {
                $items = $activeItems->whereIn('id', $tab['item_ids'])->values();
                if ($items->isEmpty()) continue;

                $tabSubtotal = (float) $items->sum('subtotal');
                $tipShare = $orderSubtotal > 0
                    ? round($orderTip * $tabSubtotal / $orderSubtotal, 2)
                    : 0;

                $tableLabel = $order->table?->code ?? ($order->is_takeaway ? 'Para llevar' : 'Sin mesa');
                $tabLabel = $tab['label'] ?? null;
                $notes = "Orden restaurante {$order->fullNumber()} — {$tableLabel}"
                    .($tabLabel ? " — Tab {$tabLabel}" : '')
                    .($tipShare > 0 ? sprintf(' — Propina: $%s', number_format($tipShare, 0, ',', '.')) : '');

                $invoice = SaleInvoice::create([
                    'company_id' => $order->company_id,
                    'location_id' => $order->location_id,
                    'third_party_id' => $thirdPartyId,
                    'prefix' => 'POS',
                    'number' => $numberer->next($company, 'POS'),
                    'date' => now()->toDateString(),
                    'currency' => 'COP',
                    'status' => 'draft',
                    'payment_status' => 'pendiente',
                    'created_by_user_id' => Auth::id(),
                    'seller_user_id' => $order->server_user_id ?? Auth::id(),
                    'cash_register_session_id' => $order->cash_register_session_id,
                    'notes' => $notes,
                ]);

                $lineNum = 1;
                foreach ($items as $item) {
                    // unit_price efectivo incluye delta de modificadores
                    // (item.subtotal = qty * unit_price + modifier_total)
                    $effUnit = (float) $item->quantity > 0
                        ? round((float) $item->subtotal / (float) $item->quantity, 2)
                        : 0;

                    $invoice->lines()->create([
                        'line_number' => $lineNum++,
                        'product_id' => $item->product_id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $effUnit,
                        'discount_percentage' => 0,
                        'discount_amount' => 0,
                        'tax_id' => $item->tax_id,
                        'tax_rate' => $item->tax_rate,
                        'tax_amount' => $item->tax_amount,
                        'subtotal' => $item->subtotal,
                        'total' => $item->total,
                    ]);
                }

                // Postear (recalcula totales internamente)
                $invoice = $invoiceEngine->post($invoice->fresh(['lines']));

                // Aplicar pagos. Soporta multi-pago: $tab['payments'] es array
                // de {payment_method, account_id, amount}. Compat con la API
                // antigua single-payment: si no viene 'payments', usa
                // payment_method + account_id del tab.
                $payments = $tab['payments'] ?? null;
                if (! is_array($payments) || empty($payments)) {
                    $payments = [[
                        'payment_method' => $tab['payment_method'] ?? 'cash',
                        'account_id' => (int) ($tab['account_id'] ?? 0),
                        'amount' => (float) $invoice->total,
                    ]];
                }

                // Validar que la suma de pagos cubre exactamente invoice.total
                $sum = round((float) array_sum(array_map(fn ($p) => (float) $p['amount'], $payments)), 2);
                $target = round((float) $invoice->total, 2);
                if (abs($sum - $target) > 0.01) {
                    throw new RuntimeException(sprintf(
                        'Los pagos del tab%s suman $%s pero la factura es $%s. Diferencia: $%s.',
                        $tabLabel ? ' '.$tabLabel : '',
                        number_format($sum, 0, ',', '.'),
                        number_format($target, 0, ',', '.'),
                        number_format(abs($sum - $target), 0, ',', '.'),
                    ));
                }

                foreach ($payments as $idx => $p) {
                    $amount = round((float) $p['amount'], 2);
                    if ($amount <= 0) continue;
                    if (empty($p['account_id'])) {
                        throw new RuntimeException('Falta cuenta contable en uno de los pagos.');
                    }
                    $invoiceEngine->addPayment($invoice, [
                        'amount' => $amount,
                        'payment_method' => $p['payment_method'] ?? 'cash',
                        'account_id' => (int) $p['account_id'],
                        'date' => now()->toDateString(),
                        'reference' => $tab['reference'] ?? ($p['reference'] ?? null),
                        'description' => 'Orden restaurante '.$order->fullNumber()
                            .($tabLabel ? ' tab '.$tabLabel : '')
                            .(count($payments) > 1 ? ' (pago '.($idx + 1).'/'.count($payments).')' : ''),
                    ]);
                }

                // Asiento contable de propina del tab (si configurado y >0)
                if ($tipShare > 0) {
                    $firstPaymentAccountId = (int) ($payments[0]['account_id'] ?? 0);
                    if ($firstPaymentAccountId > 0) {
                        $this->recordTipJournalEntry(
                            $order,
                            $invoice,
                            $tipShare,
                            $firstPaymentAccountId,
                            $tabLabel,
                        );
                    }
                }

                $invoice = $invoice->fresh();
                $invoices[] = $invoice;

                // Encolar impresión del recibo (se ejecuta tras el commit)
                $receiptJobs[] = [
                    'invoice' => $invoice,
                    'tip' => $tipShare,
                    'payments' => $payments,
                    'tab_label' => $tabLabel,
                ];
            }

            // Marcar todos los items como servidos (los que estaban pendientes
            // quedarian inconsistentes; en flujo normal ya estarian served via KDS)
            foreach ($activeItems as $item) {
                if ($item->kitchen_status !== OrderItem::KS_SERVED) {
                    $item->update([
                        'kitchen_status' => OrderItem::KS_SERVED,
                        'served_at' => $item->served_at ?? now(),
                    ]);
                }
            }

            // Cerrar la orden y liberar la mesa. Linkea los invoice_ids para
            // trazabilidad bidireccional desde la orden.
            $invoiceIds = array_map(fn ($inv) => $inv->id, $invoices);
            $order->update([
                'status' => Order::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by_user_id' => Auth::id(),
                'invoice_ids' => $invoiceIds,
                'notes' => trim(($order->notes ?? '')."\nCerrada. Facturas: "
                    .implode(', ', array_map(fn ($i) => $i->fullNumber(), $invoices))),
            ]);

            if ($order->table) {
                $order->table->update(['status' => 'free']);
            }

            return $invoices;
        });

        // Imprimir recibos POR ESC/POS tras el commit. Si falla (impresora
        // apagada, sin impresora de caja), no aborta — la venta ya está hecha.
        $receiptPrinter = app(RestaurantReceiptPrinter::class);
        foreach ($receiptJobs as $job) {
            $receiptPrinter->printReceipt(
                $job['invoice'],
                $order,
                (float) $job['tip'],
                $job['payments'] ?? [],
                $job['tab_label'] ?? null,
            );
        }

        return $invoices;
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

    /**
     * Crea el asiento contable de la propina recibida:
     *   DR cuenta de caja/banco (donde entró el dinero)
     *   CR cuenta de "propinas por pagar" (pasivo al staff)
     *
     * Solo se ejecuta si la empresa tiene configurada la cuenta en
     * settings.restaurant.tip_payable_account_id. Si no, el asiento NO se
     * crea — la propina sigue registrada en order.tip_amount para reportes,
     * pero el dinero queda fuera del journal hasta que el admin lo asigne.
     */
    protected function recordTipJournalEntry(
        Order $order,
        SaleInvoice $invoice,
        float $tipAmount,
        int $cashAccountId,
        ?string $tabLabel = null,
    ): ?\App\Models\JournalEntry {
        $company = \App\Models\Company::find($order->company_id);
        $settings = $company?->settings ?? [];
        $tipAccountId = data_get($settings, 'restaurant.tip_payable_account_id');

        if (! $tipAccountId) {
            return null; // sin cuenta configurada, no se crea asiento
        }

        $numberer = app(\App\Services\Accounting\JournalEntryNumberer::class);
        $number = $numberer->next($company, 'PR'); // PR = Propina Recibida

        $description = "Propina recibida — Orden {$order->fullNumber()}"
            .($tabLabel ? " tab {$tabLabel}" : '')
            ." (Factura {$invoice->fullNumber()})";

        $entry = \App\Models\JournalEntry::create([
            'company_id' => $order->company_id,
            'prefix' => 'PR',
            'number' => $number,
            'date' => now()->toDateString(),
            'type' => 'receipt',
            'reference' => $invoice->fullNumber(),
            'description' => $description,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $tipAmount,
            'total_credit' => $tipAmount,
        ]);

        \App\Models\JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 1,
            'account_id' => $cashAccountId,
            'description' => 'Propina recibida en efectivo/banco',
            'debit' => $tipAmount,
            'credit' => 0,
        ]);

        \App\Models\JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 2,
            'account_id' => (int) $tipAccountId,
            'description' => 'Propina por pagar al staff — Orden '.$order->fullNumber(),
            'debit' => 0,
            'credit' => $tipAmount,
        ]);

        return $entry;
    }
}
