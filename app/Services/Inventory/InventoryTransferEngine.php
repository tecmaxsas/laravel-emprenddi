<?php

namespace App\Services\Inventory;

use App\Models\InventoryTransfer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de transferencias de inventario entre sedes. Al postearse crea
 * un movimiento transfer_out en origen y uno transfer_in en destino por
 * cada línea, ambos con el mismo reference_id (= transfer.id) para
 * trazar la operación. No genera asiento contable: el inventario sigue
 * siendo el mismo activo (1435 mercancía) en otra sede física —
 * contablemente es transparente.
 */
class InventoryTransferEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
    ) {}

    public function post(InventoryTransfer $transfer): InventoryTransfer
    {
        if (! $transfer->isDraft()) {
            throw new RuntimeException('Solo se pueden contabilizar transferencias en borrador.');
        }

        if ($transfer->from_location_id === $transfer->to_location_id) {
            throw new RuntimeException('La sede origen y destino deben ser distintas.');
        }

        $transfer->loadMissing(['lines.product', 'fromLocation', 'toLocation']);

        if ($transfer->lines->isEmpty()) {
            throw new RuntimeException('La transferencia no tiene líneas.');
        }

        return DB::transaction(function () use ($transfer) {
            foreach ($transfer->lines as $line) {
                $product = $line->product;
                if (! $product) {
                    throw new RuntimeException("Línea {$line->line_number}: producto no encontrado.");
                }
                if (! $product->track_inventory) {
                    throw new RuntimeException(
                        "Producto {$product->code} no controla inventario — no se puede transferir."
                    );
                }

                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    throw new RuntimeException("Línea {$line->line_number}: cantidad debe ser mayor a 0.");
                }

                // Costo unitario = costo promedio actual en sede origen.
                // Si origen tiene 0 stock con costo 0 y aún hay quantity, el
                // addMovement de salida ya falla con "stock insuficiente".
                $unitCost = $this->inventory->currentAvgCost($product->id, $transfer->from_location_id);

                $outMovement = $this->inventory->addMovement(
                    $product,
                    $transfer->fromLocation,
                    [
                        'type' => 'transfer_out',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'date' => $transfer->date,
                        'reference_type' => InventoryTransfer::class,
                        'reference_id' => $transfer->id,
                        'reference_number' => $transfer->fullNumber(),
                        'description' => "Transferencia {$transfer->fullNumber()} → {$transfer->toLocation->name}",
                    ],
                );

                $inMovement = $this->inventory->addMovement(
                    $product,
                    $transfer->toLocation,
                    [
                        'type' => 'transfer_in',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'date' => $transfer->date,
                        'reference_type' => InventoryTransfer::class,
                        'reference_id' => $transfer->id,
                        'reference_number' => $transfer->fullNumber(),
                        'description' => "Transferencia {$transfer->fullNumber()} ← {$transfer->fromLocation->name}",
                    ],
                );

                $line->update([
                    'unit_cost' => $unitCost,
                    'out_movement_id' => $outMovement->id,
                    'in_movement_id' => $inMovement->id,
                ]);
            }

            $transfer->update([
                'status' => InventoryTransfer::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
            ]);

            return $transfer->fresh(['lines']);
        });
    }

    /**
     * Cancela una transferencia posteada: crea movimientos espejo
     * (transfer_in en origen + transfer_out en destino) para revertir.
     */
    public function cancel(InventoryTransfer $transfer, ?string $reason = null): InventoryTransfer
    {
        if (! $transfer->isPosted()) {
            throw new RuntimeException('Solo se pueden anular transferencias contabilizadas.');
        }

        $transfer->loadMissing(['lines.product', 'fromLocation', 'toLocation']);

        return DB::transaction(function () use ($transfer, $reason) {
            foreach ($transfer->lines as $line) {
                if (! $line->product || ! $line->out_movement_id || ! $line->in_movement_id) {
                    continue;
                }

                $qty = (float) $line->quantity;
                $unitCost = (float) $line->unit_cost;

                // Reverso destino → origen
                $this->inventory->addMovement(
                    $line->product,
                    $transfer->toLocation,
                    [
                        'type' => 'transfer_out',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'date' => now()->toDateString(),
                        'reference_type' => InventoryTransfer::class,
                        'reference_id' => $transfer->id,
                        'reference_number' => 'REV-'.$transfer->fullNumber(),
                        'description' => "Reverso transferencia {$transfer->fullNumber()}".($reason ? ' — '.$reason : ''),
                    ],
                );
                $this->inventory->addMovement(
                    $line->product,
                    $transfer->fromLocation,
                    [
                        'type' => 'transfer_in',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'date' => now()->toDateString(),
                        'reference_type' => InventoryTransfer::class,
                        'reference_id' => $transfer->id,
                        'reference_number' => 'REV-'.$transfer->fullNumber(),
                        'description' => "Reverso transferencia {$transfer->fullNumber()}".($reason ? ' — '.$reason : ''),
                    ],
                );
            }

            $transfer->update([
                'status' => InventoryTransfer::STATUS_CANCELLED,
                'notes' => trim(($transfer->notes ?? '')."\nAnulada: ".($reason ?? 'sin motivo')),
            ]);

            return $transfer->fresh();
        });
    }
}
