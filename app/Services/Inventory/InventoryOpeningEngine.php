<?php

namespace App\Services\Inventory;

use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryOpening;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalEntryNumberer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de apertura de inventario. Carga el saldo inicial de productos
 * en una sede sin pasar por compra. Genera movimientos type=opening +
 * un único asiento:
 *
 *   DR  cuenta de inventario del producto (por línea/grupo)
 *   CR  counterpart_account_id  (capital o resultados acumulados)
 */
class InventoryOpeningEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $journalNumberer,
    ) {}

    public function post(InventoryOpening $opening): InventoryOpening
    {
        if (! $opening->isDraft()) {
            throw new RuntimeException('Solo se pueden contabilizar aperturas en borrador.');
        }

        $opening->loadMissing(['lines.product', 'location', 'counterpartAccount']);

        if ($opening->lines->isEmpty()) {
            throw new RuntimeException('La apertura no tiene líneas.');
        }

        return DB::transaction(function () use ($opening) {
            $totalCost = 0.0;
            $byAccount = []; // {inv_account_id => total}

            foreach ($opening->lines as $line) {
                $product = $line->product;
                if (! $product) {
                    throw new RuntimeException("Línea {$line->line_number}: producto no encontrado.");
                }
                if (! $product->track_inventory) {
                    throw new RuntimeException("Producto {$product->code} no controla inventario.");
                }

                $qty = (float) $line->quantity;
                $unitCost = (float) $line->unit_cost;

                if ($qty <= 0) {
                    throw new RuntimeException("Línea {$line->line_number}: cantidad debe ser mayor a 0.");
                }
                if ($unitCost <= 0) {
                    throw new RuntimeException("Línea {$line->line_number}: costo unitario debe ser mayor a 0.");
                }

                $movement = $this->inventory->addMovement(
                    $product,
                    $opening->location,
                    [
                        'type' => 'opening',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'date' => $opening->date,
                        'reference_type' => InventoryOpening::class,
                        'reference_id' => $opening->id,
                        'reference_number' => $opening->fullNumber(),
                        'description' => "Apertura {$opening->fullNumber()} — {$product->name}",
                    ],
                );

                $line->update(['inventory_movement_id' => $movement->id]);

                $lineCost = round($qty * $unitCost, 2);
                $totalCost += $lineCost;

                $invAccountId = $product->effectiveInventoryAccountId()
                    ?? $this->defaultInventoryAccountId($opening->company_id);
                if (! $invAccountId) {
                    throw new RuntimeException(
                        "Producto {$product->code} sin cuenta de inventario. Configúrala en el producto o crea la cuenta 1435 en el PUC."
                    );
                }
                $byAccount[$invAccountId] = ($byAccount[$invAccountId] ?? 0) + $lineCost;
            }

            $entry = $this->createJournalEntry($opening, $byAccount, $totalCost);

            $opening->update([
                'status' => InventoryOpening::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
            ]);

            return $opening->fresh(['lines']);
        });
    }

    protected function createJournalEntry(InventoryOpening $opening, array $byAccount, float $totalCost): JournalEntry
    {
        $company = Company::find($opening->company_id);
        $number = $this->journalNumberer->next($company, 'AS');

        $entry = JournalEntry::create([
            'company_id' => $opening->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => $opening->date,
            'type' => 'opening',
            'reference' => $opening->fullNumber(),
            'description' => 'Apertura de inventario '.$opening->fullNumber().' — '.$opening->location->name,
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => round($totalCost, 2),
            'total_credit' => round($totalCost, 2),
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
        ]);

        $lineNum = 1;

        // DR cuenta(s) de inventario
        foreach ($byAccount as $accountId => $amount) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $accountId,
                'debit' => round($amount, 2),
                'credit' => 0,
                'description' => 'Entrada por apertura',
            ]);
        }

        // CR counterpart (capital / resultados)
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => $lineNum++,
            'account_id' => $opening->counterpart_account_id,
            'debit' => 0,
            'credit' => round($totalCost, 2),
            'description' => 'Contraparte apertura inventario',
        ]);

        return $entry;
    }

    public function cancel(InventoryOpening $opening, ?string $reason = null): InventoryOpening
    {
        if (! $opening->isPosted()) {
            throw new RuntimeException('Solo se pueden anular aperturas contabilizadas.');
        }

        $opening->loadMissing(['lines.product', 'location', 'journalEntry.lines']);

        return DB::transaction(function () use ($opening, $reason) {
            // Reverso de movimientos: adjustment_out (no podemos hacer opening_out)
            foreach ($opening->lines as $line) {
                if (! $line->product || ! $line->inventory_movement_id) continue;

                $this->inventory->addMovement(
                    $line->product,
                    $opening->location,
                    [
                        'type' => 'adjustment_out',
                        'quantity' => (float) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                        'date' => now()->toDateString(),
                        'reference_type' => InventoryOpening::class,
                        'reference_id' => $opening->id,
                        'reference_number' => 'REV-'.$opening->fullNumber(),
                        'description' => 'Reverso apertura '.$opening->fullNumber().($reason ? ' — '.$reason : ''),
                    ],
                );
            }

            // Reverso del asiento
            if ($opening->journal_entry_id && $opening->journalEntry) {
                $original = $opening->journalEntry;
                $company = Company::find($opening->company_id);
                $number = $this->journalNumberer->next($company, 'AS');

                $reverso = JournalEntry::create([
                    'company_id' => $opening->company_id,
                    'prefix' => 'AS',
                    'number' => $number,
                    'date' => now()->toDateString(),
                    'type' => 'reversal',
                    'reference' => 'REV-'.$opening->fullNumber(),
                    'description' => 'Reverso apertura '.$opening->fullNumber().($reason ? ' — '.$reason : ''),
                    'status' => JournalEntry::STATUS_POSTED,
                    'total_debit' => (float) $original->total_credit,
                    'total_credit' => (float) $original->total_debit,
                    'posted_at' => now(),
                    'posted_by_user_id' => Auth::id(),
                    'created_by_user_id' => Auth::id(),
                    'reversed_entry_id' => $original->id,
                ]);

                $lineNum = 1;
                foreach ($original->lines as $line) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $reverso->id,
                        'line_number' => $lineNum++,
                        'account_id' => $line->account_id,
                        'third_party_id' => $line->third_party_id,
                        'debit' => $line->credit,
                        'credit' => $line->debit,
                        'description' => 'Reverso: '.$line->description,
                    ]);
                }
            }

            $opening->update([
                'status' => InventoryOpening::STATUS_CANCELLED,
                'notes' => trim(($opening->notes ?? '')."\nAnulado: ".($reason ?? 'sin motivo')),
            ]);

            return $opening->fresh();
        });
    }

    protected function defaultInventoryAccountId(int $companyId): ?int
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', '1435')
            ->value('id');
    }
}
