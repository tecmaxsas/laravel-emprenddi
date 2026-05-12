<?php

namespace App\Services\Assets;

use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalEntryNumberer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de activos fijos. Maneja:
 *  - depreciar(asset, year, month): genera el asiento mensual y la fila
 *    en fixed_asset_depreciations. Idempotente por unique constraint.
 *  - depreciarPeriodo(year, month, company): pasada masiva sobre todos
 *    los activos elegibles.
 *  - disposeAsset(asset, date, salePrice): cierra el activo, genera
 *    asiento de baja según haya ganancia o pérdida en la venta.
 */
class FixedAssetEngine
{
    public function __construct(
        protected JournalEntryNumberer $journalNumberer,
    ) {}

    /**
     * Deprecia un activo en (year, month). Devuelve la fila creada o null
     * si el activo no aplica (ya depreciado ese mes, ya disposed, ya
     * totalmente depreciado, etc.).
     */
    public function depreciate(FixedAsset $asset, int $year, int $month): ?FixedAssetDepreciation
    {
        if (! $asset->isActive()) return null;
        if (! $asset->activated_at) return null;
        if ($asset->fullyDepreciated()) return null;

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        // No depreciar si la activación es posterior al fin del mes
        if ($asset->activated_at->gt($periodEnd)) return null;

        // No depreciar después de disposed_at
        if ($asset->disposed_at && $asset->disposed_at->lt($periodStart)) return null;

        $exists = FixedAssetDepreciation::query()
            ->where('fixed_asset_id', $asset->id)
            ->where('year', $year)
            ->where('month', $month)
            ->exists();
        if ($exists) return null;

        $monthly = $asset->monthlyDepreciation();
        $remaining = (float) $asset->cost - (float) $asset->residual_value - (float) $asset->accumulated_depreciation;
        $amount = min($monthly, max($remaining, 0));

        if ($amount <= 0.005) return null;

        return DB::transaction(function () use ($asset, $year, $month, $amount) {
            $company = Company::find($asset->company_id);
            $entryNumber = $this->journalNumberer->next($company, 'AS');

            $entry = JournalEntry::create([
                'company_id' => $asset->company_id,
                'prefix' => 'AS',
                'number' => $entryNumber,
                'date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
                'type' => 'depreciation',
                'reference' => 'DEP-'.$asset->code,
                'description' => sprintf('Depreciación %s-%02d — %s', $year, $month, $asset->name),
                'status' => JournalEntry::STATUS_POSTED,
                'total_debit' => round($amount, 2),
                'total_credit' => round($amount, 2),
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'created_by_user_id' => Auth::id(),
            ]);

            // DR gasto depreciación
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => 1,
                'account_id' => $asset->depreciation_expense_account_id,
                'description' => 'Gasto depreciación '.$asset->code,
                'debit' => round($amount, 2),
                'credit' => 0,
            ]);
            // CR depreciación acumulada
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => 2,
                'account_id' => $asset->depreciation_account_id,
                'description' => 'Depreciación acumulada '.$asset->code,
                'debit' => 0,
                'credit' => round($amount, 2),
            ]);

            $row = FixedAssetDepreciation::create([
                'fixed_asset_id' => $asset->id,
                'year' => $year,
                'month' => $month,
                'amount' => $amount,
                'journal_entry_id' => $entry->id,
                'created_by_user_id' => Auth::id(),
            ]);

            $asset->update([
                'accumulated_depreciation' => (float) $asset->accumulated_depreciation + $amount,
            ]);

            return $row;
        });
    }

    /**
     * Corre la depreciación de todos los activos elegibles de la empresa
     * para el período (year, month). Devuelve el número de filas creadas.
     */
    public function depreciatePeriod(int $companyId, int $year, int $month): int
    {
        $count = 0;
        $assets = FixedAsset::query()
            ->where('company_id', $companyId)
            ->where('status', FixedAsset::STATUS_ACTIVE)
            ->whereNotNull('activated_at')
            ->get();

        foreach ($assets as $asset) {
            if ($this->depreciate($asset, $year, $month)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Da de baja el activo. Genera asiento de venta/baja con ganancia o
     * pérdida según el precio de venta vs. el valor en libros.
     *
     * Estructura del asiento (cuando hay venta con efectivo recibido):
     *   DR Caja/CxC (salePrice)
     *   DR Depreciación acumulada (accumulated_depreciation)  -- cancela contracuenta
     *   DR/CR Pérdida/Ganancia en venta de activos (4220/5310 según signo)
     *   CR Activo (cost)
     *
     * Si no hay precio (baja sin recuperación):
     *   DR Depreciación acumulada (accumulated_depreciation)
     *   DR Pérdida en baja (5310/5295)
     *   CR Activo (cost)
     */
    public function dispose(FixedAsset $asset, string $disposalDate, float $salePrice, ?int $cashAccountId, ?int $gainLossAccountId, ?string $notes = null): FixedAsset
    {
        if ($asset->isDisposed()) {
            throw new RuntimeException('El activo ya está dado de baja.');
        }

        $cost = (float) $asset->cost;
        $accumulated = (float) $asset->accumulated_depreciation;
        $bookValue = round($cost - $accumulated, 2);
        $diff = round($salePrice - $bookValue, 2);

        if (! $gainLossAccountId) {
            throw new RuntimeException('Falta cuenta de ganancia/pérdida en baja de activos.');
        }
        if ($salePrice > 0 && ! $cashAccountId) {
            throw new RuntimeException('Falta cuenta de caja/banco si hay precio de venta.');
        }

        return DB::transaction(function () use ($asset, $disposalDate, $salePrice, $cashAccountId, $gainLossAccountId, $notes, $cost, $accumulated, $bookValue, $diff) {
            $company = Company::find($asset->company_id);
            $entryNumber = $this->journalNumberer->next($company, 'AS');

            // Total = debits = cost
            $totalDebit = $accumulated + max($salePrice, 0) + max(-$diff, 0); // accum + cash + loss (if loss)
            $totalCredit = $cost + max($diff, 0); // cost + gain (if gain)

            // Adjust to balance
            $entry = JournalEntry::create([
                'company_id' => $asset->company_id,
                'prefix' => 'AS',
                'number' => $entryNumber,
                'date' => $disposalDate,
                'type' => 'adjustment',
                'reference' => 'BAJA-'.$asset->code,
                'description' => 'Baja de activo '.$asset->code.' — '.$asset->name,
                'status' => JournalEntry::STATUS_POSTED,
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'created_by_user_id' => Auth::id(),
            ]);

            $lineNum = 1;

            // DR Depreciación acumulada (cancela contracuenta)
            if ($accumulated > 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $lineNum++,
                    'account_id' => $asset->depreciation_account_id,
                    'description' => 'Cancelar depreciación acumulada '.$asset->code,
                    'debit' => round($accumulated, 2),
                    'credit' => 0,
                ]);
            }

            // DR Caja/banco si hubo precio
            if ($salePrice > 0 && $cashAccountId) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $lineNum++,
                    'account_id' => $cashAccountId,
                    'description' => 'Ingreso por venta de activo',
                    'debit' => round($salePrice, 2),
                    'credit' => 0,
                ]);
            }

            // DR Pérdida si salePrice < bookValue
            if ($diff < 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $lineNum++,
                    'account_id' => $gainLossAccountId,
                    'description' => 'Pérdida en venta/baja de activo',
                    'debit' => round(-$diff, 2),
                    'credit' => 0,
                ]);
            }

            // CR Activo
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $asset->asset_account_id,
                'description' => 'Dar de baja activo '.$asset->code,
                'debit' => 0,
                'credit' => round($cost, 2),
            ]);

            // CR Ganancia si salePrice > bookValue
            if ($diff > 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $lineNum++,
                    'account_id' => $gainLossAccountId,
                    'description' => 'Ganancia en venta de activo',
                    'debit' => 0,
                    'credit' => round($diff, 2),
                ]);
            }

            $asset->update([
                'status' => FixedAsset::STATUS_DISPOSED,
                'disposed_at' => $disposalDate,
                'disposal_sale_price' => $salePrice,
                'disposal_journal_entry_id' => $entry->id,
                'disposal_notes' => $notes,
            ]);

            return $asset->fresh();
        });
    }
}
