<?php

namespace App\Services\Commissions;

use App\Models\CommissionEntry;
use App\Models\CommissionSettlement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Support\CommissionsSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Liquida las comisiones pendientes de un vendedor en un periodo:
 *  1. Reune las CommissionEntry 'pending' con causacion en el rango.
 *  2. Crea la CommissionSettlement con el total.
 *  3. Marca las entries como 'settled' + las liga a la liquidacion.
 *  4. Genera el asiento contable DR Gasto comisiones / CR Comisiones por pagar.
 *
 * Todo en una transaccion. Idempotencia natural: las entries ya
 * settled no se vuelven a tomar.
 */
class CommissionSettlementEngine
{
    public function settle(int $sellerId, string $periodStart, string $periodEnd, ?string $notes = null): CommissionSettlement
    {
        $companyId = Auth::user()->company_id;
        $company = Company::find($companyId);

        $entries = CommissionEntry::query()
            ->where('company_id', $companyId)
            ->where('seller_user_id', $sellerId)
            ->where('status', CommissionEntry::STATUS_PENDING)
            ->whereDate('causation_date', '>=', $periodStart)
            ->whereDate('causation_date', '<=', $periodEnd)
            ->get();

        if ($entries->isEmpty()) {
            throw new RuntimeException('No hay comisiones pendientes para ese vendedor en el período seleccionado.');
        }

        $expenseAccountId = CommissionsSettings::expenseAccountId();
        $payableAccountId = CommissionsSettings::payableAccountId();
        if (! $expenseAccountId || ! $payableAccountId) {
            throw new RuntimeException('Configura las cuentas contables de comisiones (gasto y por pagar) en Configuraciones → Comisiones.');
        }

        $total = round((float) $entries->sum('amount'), 2);
        $seller = User::find($sellerId);
        $sellerName = $seller ? trim($seller->name.' '.$seller->last_name) : "Vendedor #{$sellerId}";

        return DB::transaction(function () use (
            $companyId, $company, $sellerId, $sellerName, $periodStart, $periodEnd,
            $entries, $total, $expenseAccountId, $payableAccountId, $notes
        ) {
            // 1. Crear liquidacion
            $settlement = CommissionSettlement::create([
                'company_id' => $companyId,
                'seller_user_id' => $sellerId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_amount' => $total,
                'entries_count' => $entries->count(),
                'status' => CommissionSettlement::STATUS_DRAFT,
                'notes' => $notes,
            ]);

            // 2. Marcar entries como settled + ligar
            CommissionEntry::query()
                ->whereIn('id', $entries->pluck('id'))
                ->update([
                    'status' => CommissionEntry::STATUS_SETTLED,
                    'commission_settlement_id' => $settlement->id,
                ]);

            // 3. Asiento contable: DR Gasto comisiones / CR Comisiones por pagar
            $numberer = app(\App\Services\Accounting\JournalEntryNumberer::class);
            $number = $numberer->next($company, 'CM'); // CM = Comisiones

            $description = "Liquidación comisiones {$sellerName} — "
                .date('d/m/Y', strtotime($periodStart)).' a '.date('d/m/Y', strtotime($periodEnd));

            $entry = JournalEntry::create([
                'company_id' => $companyId,
                'prefix' => 'CM',
                'number' => $number,
                'date' => now()->toDateString(),
                'type' => 'general',
                'reference' => 'LIQ-COM-'.$settlement->id,
                'description' => $description,
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'created_by_user_id' => Auth::id(),
                'total_debit' => $total,
                'total_credit' => $total,
            ]);

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => 1,
                'account_id' => $expenseAccountId,
                'description' => "Gasto comisiones {$sellerName}",
                'debit' => $total,
                'credit' => 0,
            ]);

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => 2,
                'account_id' => $payableAccountId,
                'description' => "Comisiones por pagar a {$sellerName}",
                'debit' => 0,
                'credit' => $total,
            ]);

            // 4. Marcar liquidacion como paid + ligar asiento
            $settlement->update([
                'status' => CommissionSettlement::STATUS_PAID,
                'journal_entry_id' => $entry->id,
                'settled_at' => now(),
                'settled_by_user_id' => Auth::id(),
            ]);

            return $settlement->fresh();
        });
    }
}
