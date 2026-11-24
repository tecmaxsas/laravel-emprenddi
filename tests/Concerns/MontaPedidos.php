<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Location;
use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\DeliveryItem;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\OrderTaking\OrderRetention;
use App\Models\OrderTaking\Payment;
use App\Models\Product;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\OrderTaking\OrderEngine;
use Illuminate\Support\Facades\DB;

/**
 * El escenario mínimo de Toma pedidos: cliente, producto, pedido de
 * $1.000.000 + IVA, y una sede con resolución para poder facturar.
 *
 * Estaba escrito dentro de una sola prueba y copiarlo a la siguiente habría
 * dejado dos escenarios que se separan al primer cambio del módulo —y el
 * que se queda viejo es el que falla por un motivo que no tiene nada que
 * ver con lo que la prueba quería comprobar—.
 *
 * Trabaja sobre la empresa de la base de desarrollo, que ya tiene PUC e
 * impuestos: levantar una empresa entera por prueba significaría sembrar el
 * plan de cuentas cada vez. Todo lo que crea lleva prefijo ZZ y se borra en
 * la cola de limpieza.
 */
trait MontaPedidos
{
    protected User $user;

    protected int $companyId;

    /**
     * El usuario de la prueba, elegido a propósito.
     *
     * `orderBy('id')` y no «el primero que salga»: sin ORDER BY, Postgres
     * devuelve la fila que tenga más a mano, y eso cambia con cualquier
     * inserción en la base de desarrollo. Ya pasó —una prueba empezó a
     * fallar con 403 el día que otra sembró una empresa demo— y el fallo
     * parecía una regresión del código.
     */
    protected function tomaElUsuarioDePruebas(): void
    {
        $this->user = User::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->firstOrFail();

        $this->companyId = (int) $this->user->company_id;
    }

    protected function cliente(): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'document_number' => 'ZZ-RET-001'],
            [
                'person_type' => 'juridica',
                'document_type' => 'nit',
                'name' => 'ZZ Cliente Retenedor',
                'is_customer' => true,
                'active' => true,
            ]
        );

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()->whereKey($cliente->id)->forceDelete();

        return $cliente;
    }

    protected function retencion(float $rate = 2.5): Tax
    {
        $tax = Tax::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'code' => 'ZZRF'],
            [
                'name' => 'ZZ ReteFuente',
                'type' => 'income_withholding',
                'rate' => $rate,
                'applies_to' => 'sale',
                'is_active' => true,
                // Sin cuenta de venta el motor de facturas rechaza la
                // retencion: es donde se debita el anticipo de impuesto.
                'sale_account_id' => Account::withoutGlobalScopes()
                    ->where('company_id', $this->companyId)
                    ->where('accepts_movements', true)
                    ->where('code', 'like', '13%')
                    ->value('id'),
            ]
        );

        // firstOrCreate no actualiza lo que ya existe, y la cuenta es
        // obligatoria para el motor de facturas.
        $tax->update([
            'rate' => $rate,
            'sale_account_id' => Account::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->where('accepts_movements', true)
                ->where('code', 'like', '13%')
                ->value('id'),
        ]);

        $this->limpiar[] = fn () => Tax::withoutGlobalScopes()->whereKey($tax->id)->forceDelete();

        return $tax->refresh();
    }

    protected function producto(): Product
    {
        $producto = Product::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'code' => 'ZZ-PROD-1'],
            ['name' => 'ZZ Producto']
        );

        $this->limpiar[] = function () use ($producto) {
            // Facturar deja movimientos de inventario colgando del producto.
            DB::table('inventory_movements')->where('product_id', $producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($producto->id)->forceDelete();
        };

        return $producto;
    }

    /** Pedido de $1.000.000 + IVA 19% = $1.190.000. */
    protected function pedidoDe(ThirdParty $cliente): Order
    {
        $engine = app(OrderEngine::class);

        // Antes que el pedido: tearDown limpia en orden inverso, asi el pedido
        // se borra primero y el producto queda sin referencias.
        $producto = Product::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'code' => 'ZZ-PROD-1'],
            ['name' => 'ZZ Producto']
        );

        $this->limpiar[] = function () use ($producto) {
            // Facturar deja movimientos de inventario colgando del producto.
            DB::table('inventory_movements')->where('product_id', $producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($producto->id)->forceDelete();
        };

        $order = Order::create([
            'company_id' => $this->companyId,
            'prefix' => 'ZZP',
            'number' => $engine->reserveNumber($this->companyId, 'ZZP'),
            'third_party_id' => $cliente->id,
            'seller_user_id' => $this->user->id,
            'created_by_user_id' => $this->user->id,
            'order_date' => now()->toDateString(),
            'status' => Order::STATUS_CONFIRMED,
            'delivery_status' => 'pending',
            'payment_status' => 'pendiente',
        ]);

        // El borrado del pedido no arrastra a sus hijos, asi que se limpian a mano
        // y en orden: abonos y despachos antes que las lineas.
        $this->limpiar[] = function () use ($order) {
            Payment::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            DeliveryItem::withoutGlobalScopes()
                ->whereIn('delivery_id', Delivery::withoutGlobalScopes()->where('order_id', $order->id)->pluck('id'))
                ->forceDelete();
            Delivery::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            OrderRetention::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            Order::withoutGlobalScopes()->whereKey($order->id)->forceDelete();
        };

        OrderItem::create([
            'company_id' => $this->companyId,
            'order_id' => $order->id,
            'product_id' => $producto->id,
            'line_number' => 1,
            'description' => 'ZZ Producto',
            'quantity_ordered' => 10,
            'quantity_delivered' => 0,
            'unit_price_before_tax' => 100000,
            'tax_rate' => 19,
            // IVA de la LINEA (10 × 19.000), igual que subtotal y total.
            'tax_amount' => 190000,
            'unit_price_at_public' => 119000,
            'subtotal' => 1000000,
            'total' => 1190000,
        ]);

        return $order->fresh(['items']);
    }

    /** Sede con resolucion POS: sin esto no hay numeracion para la factura. */
    protected function sedeConResolucion(): Location
    {
        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->firstOrFail();

        $res = Resolution::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'prefix' => 'ZZF'],
            [
                'kind' => Resolution::KIND_POS,
                'document_type_id' => 1,
                // Del catálogo del modelo, no a mano: el tipo 1 es «Factura
                // Electrónica» para la DIAN, y un nombre que no corresponde
                // al código es lo que `DianDocumentTypeCodesTest` impide.
                'document_type_name' => Resolution::DOCUMENT_TYPES[1],
                'resolution_number' => 'ZZ-TEST',
                'range_from' => 1,
                'range_to' => 999999,
                'active' => true,
            ]
        );

        $vinculo = LocationResolution::withoutGlobalScopes()->firstOrCreate(
            ['location_id' => $sede->id, 'dian_resolution_id' => $res->id],
            ['current_consecutive' => 0, 'active' => true]
        );

        $this->limpiar[] = fn () => LocationResolution::withoutGlobalScopes()
            ->whereKey($vinculo->id)->forceDelete();
        $this->limpiar[] = fn () => Resolution::withoutGlobalScopes()
            ->whereKey($res->id)->forceDelete();

        return $sede;
    }
}
