<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\SaleInvoice;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operaciones sobre remisiones:
 *  - dispatch(): mueve cantidades del inventario tipo delivery_out, marca
 *    dispatched, snapshotea cost_at_dispatch en cada línea.
 *  - convertToInvoice(): genera SaleInvoice draft con from_delivery_note_id
 *    y hereda inventory_movement_id por línea, para que el SaleInvoiceEngine
 *    NO genere movimientos duplicados al postear.
 */
class DeliveryNoteEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
    ) {}

    public function dispatch(DeliveryNote $note): DeliveryNote
    {
        if (! $note->canBeDispatched()) {
            throw new RuntimeException('Solo se pueden despachar remisiones en estado borrador.');
        }

        $note->load(['lines.product', 'customer', 'location']);
        if ($note->lines->isEmpty()) {
            throw new RuntimeException('La remisión no tiene líneas.');
        }

        return DB::transaction(function () use ($note) {
            foreach ($note->lines as $line) {
                if (! $line->product_id || ! $line->product?->track_inventory) {
                    continue;
                }

                $movement = $this->inventory->addMovement(
                    $line->product,
                    $note->location,
                    [
                        'type' => 'delivery_out',
                        'quantity' => (float) $line->quantity,
                        'date' => $note->date,
                        'reference_type' => DeliveryNote::class,
                        'reference_id' => $note->id,
                        'reference_number' => $note->fullNumber(),
                        'third_party_id' => $note->third_party_id,
                        'description' => "Despacho remisión {$note->fullNumber()} — {$note->customer->name}",
                    ]
                );

                $line->update([
                    'inventory_movement_id' => $movement->id,
                    'cost_at_dispatch' => $movement->unit_cost,
                ]);
            }

            $note->update([
                'status' => DeliveryNote::STATUS_DISPATCHED,
                'dispatched_at' => now(),
                'dispatched_by_user_id' => Auth::id(),
            ]);

            return $note->fresh();
        });
    }

    public function markDelivered(DeliveryNote $note): DeliveryNote
    {
        if (! $note->isDispatched()) {
            throw new RuntimeException('Solo se pueden marcar como entregadas las remisiones despachadas.');
        }

        $note->update([
            'status' => DeliveryNote::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        return $note->fresh();
    }

    public function cancel(DeliveryNote $note): DeliveryNote
    {
        if (! $note->isDraft()) {
            throw new RuntimeException('Solo se pueden cancelar remisiones en borrador. Para revertir una remisión despachada, usa una nota crédito o ajuste de inventario.');
        }

        $note->update(['status' => DeliveryNote::STATUS_CANCELLED]);

        return $note->fresh();
    }

    /**
     * Convierte una remisión despachada/entregada en SaleInvoice draft.
     * La factura hereda inventory_movement_id por línea — al postearla, el
     * SaleInvoiceEngine detecta from_delivery_note_id y NO genera movimientos
     * nuevos (sólo asienta venta + COGS desde los movimientos existentes).
     */
    public function convertToInvoice(DeliveryNote $note): SaleInvoice
    {
        if ($note->isBilled() && $note->billedAtSaleInvoice) {
            return $note->billedAtSaleInvoice;
        }

        if (! $note->canBeBilled()) {
            throw new RuntimeException('Esta remisión no puede facturarse — debe estar despachada o entregada.');
        }

        $note->load('lines.product');

        return DB::transaction(function () use ($note) {
            $company = Company::find($note->company_id);

            $invoice = SaleInvoice::create([
                'company_id' => $note->company_id,
                'location_id' => $note->location_id,
                'third_party_id' => $note->third_party_id,
                'prefix' => 'FV',
                'number' => app(SaleInvoiceNumberer::class)->next($company, 'FV'),
                'date' => now()->toDateString(),
                'currency' => 'COP',
                'status' => SaleInvoice::STATUS_DRAFT,
                'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
                'created_by_user_id' => Auth::id(),
                'seller_user_id' => $note->seller_user_id ?: Auth::id(),
                'from_delivery_note_id' => $note->id,
                'description' => 'Facturación de remisión '.$note->fullNumber(),
            ]);

            $lineNum = 1;
            foreach ($note->lines as $line) {
                // Calcular tax desde el producto si tiene default_sale_tax
                $taxId = $line->product?->effectiveSaleTaxId();
                $taxRate = 0;
                if ($taxId) {
                    $tax = \App\Models\Tax::find($taxId);
                    $taxRate = (float) ($tax?->rate ?? 0);
                }

                $unitPrice = (float) ($line->unit_price ?: $line->product?->default_sale_price ?? 0);
                $qty = (float) $line->quantity;
                $subtotal = round($qty * $unitPrice, 2);
                $taxAmount = round($subtotal * ($taxRate / 100), 2);
                $total = $subtotal + $taxAmount;

                $invoice->lines()->create([
                    'line_number' => $lineNum++,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'cost_at_sale' => $line->cost_at_dispatch,
                    'discount_percentage' => 0,
                    'discount_amount' => 0,
                    'tax_id' => $taxId,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'inventory_movement_id' => $line->inventory_movement_id, // hereda — ya descargó
                ]);
            }

            $note->update([
                'status' => DeliveryNote::STATUS_BILLED,
                'billed_at_sale_invoice_id' => $invoice->id,
                'billed_at' => now(),
            ]);

            return $invoice->fresh();
        });
    }
}
