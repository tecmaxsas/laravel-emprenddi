<?php

namespace App\Services\Exogena;

use App\Models\ExogenaAccountMapping;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\ThirdParty;
use Carbon\Carbon;

/**
 * Motor de información exógena: agrega los movimientos / saldos contables
 * del año por tercero y concepto, según el mapeo cuenta→concepto que el
 * contador configuró para cada formato.
 */
class ExogenaEngine
{
    /**
     * Construye las filas de un formato para un año gravable.
     *
     * @return array<int,array{third_party_id:?int,third_party:string,document_number:string,concept_code:string,concept_name:string,amount:float}>
     */
    public function build(string $formatCode, int $year): array
    {
        $format = ExogenaCatalog::format($formatCode);
        if (! $format) {
            return [];
        }

        return match ($format['basis']) {
            'movements' => $this->buildFromMovements($formatCode, $format, $year),
            'balance' => $this->buildFromBalances($formatCode, $format, $year),
            'partners' => $this->buildFromPartners($formatCode, $year),
            default => [], // manual — se diligencia por fuera del libro
        };
    }

    /**
     * Suma de movimientos del año gravable (formatos 1001, 1003, 1005, 1006, 1007).
     */
    protected function buildFromMovements(string $formatCode, array $format, int $year): array
    {
        $mappings = $this->mappingsFor($formatCode);
        if ($mappings->isEmpty()) {
            return [];
        }

        $rows = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $mappings->keys())
            ->where('journal_entries.status', 'posted')
            ->whereYear('journal_entries.date', $year)
            ->groupBy('journal_entry_lines.third_party_id', 'journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.third_party_id, journal_entry_lines.account_id, '
                .'SUM(journal_entry_lines.debit) AS d, SUM(journal_entry_lines.credit) AS c')
            ->get();

        return $this->aggregate($rows, $mappings, $format['side'], $formatCode);
    }

    /**
     * Saldo de las cuentas al 31 de diciembre del año (formatos 1008, 1009, 1012).
     */
    protected function buildFromBalances(string $formatCode, array $format, int $year): array
    {
        $mappings = $this->mappingsFor($formatCode);
        if ($mappings->isEmpty()) {
            return [];
        }

        $rows = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $mappings->keys())
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.date', '<=', "{$year}-12-31")
            ->groupBy('journal_entry_lines.third_party_id', 'journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.third_party_id, journal_entry_lines.account_id, '
                .'SUM(journal_entry_lines.debit) AS d, SUM(journal_entry_lines.credit) AS c')
            ->get();

        return $this->aggregate($rows, $mappings, $format['side'], $formatCode);
    }

    /**
     * Aportes de socios al 31 de diciembre (formato 1010) — desde el Libro de Socios.
     */
    protected function buildFromPartners(string $formatCode, int $year): array
    {
        $cutoff = Carbon::create($year, 12, 31)->endOfDay();

        $out = [];
        foreach (Partner::query()->with('movements')->get() as $partner) {
            $amount = round((float) $partner->movements
                ->filter(fn ($m) => $m->date && $m->date->lte($cutoff))
                ->sum('amount'), 2);

            if ($amount == 0.0) {
                continue;
            }

            $out[] = [
                'third_party_id' => null,
                'third_party' => $partner->name,
                'document_type' => $partner->document_type,
                'document_number' => $partner->document_number ?: '',
                'concept_code' => '1110',
                'concept_name' => ExogenaCatalog::conceptName($formatCode, '1110') ?? '1110',
                'amount' => $amount,
            ];
        }

        usort($out, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $out;
    }

    /** Mapeo [account_id => concept_code] del formato para la empresa actual. */
    protected function mappingsFor(string $formatCode)
    {
        return ExogenaAccountMapping::query()
            ->where('format_code', $formatCode)
            ->pluck('concept_code', 'account_id');
    }

    /**
     * Agrupa las filas crudas (tercero, cuenta, débito, crédito) por
     * tercero + concepto y resuelve el nombre del tercero.
     */
    protected function aggregate($rows, $mappings, string $side, string $formatCode): array
    {
        $bucket = [];

        foreach ($rows as $r) {
            $concept = $mappings[$r->account_id] ?? null;
            if (! $concept) {
                continue;
            }

            $amount = $side === 'credit'
                ? (float) $r->c - (float) $r->d
                : (float) $r->d - (float) $r->c;

            $key = ($r->third_party_id ?? '0').'|'.$concept;
            if (! isset($bucket[$key])) {
                $bucket[$key] = [
                    'third_party_id' => $r->third_party_id,
                    'concept_code' => $concept,
                    'amount' => 0.0,
                ];
            }
            $bucket[$key]['amount'] += $amount;
        }

        $tpIds = collect($bucket)->pluck('third_party_id')->filter()->unique()->all();
        $parties = ThirdParty::query()
            ->whereIn('id', $tpIds)
            ->get(['id', 'name', 'document_number'])
            ->keyBy('id');

        $out = [];
        foreach ($bucket as $b) {
            if (round($b['amount'], 2) == 0.0) {
                continue;
            }

            $party = $b['third_party_id'] ? $parties->get($b['third_party_id']) : null;

            $out[] = [
                'third_party_id' => $b['third_party_id'],
                'third_party' => $party?->name ?? '(Sin tercero identificado)',
                'document_number' => $party?->document_number ?: '',
                'concept_code' => $b['concept_code'],
                'concept_name' => ExogenaCatalog::conceptName($formatCode, $b['concept_code']) ?? $b['concept_code'],
                'amount' => round($b['amount'], 2),
            ];
        }

        usort($out, function ($a, $b) {
            return [$a['concept_code'], -$a['amount']] <=> [$b['concept_code'], -$b['amount']];
        });

        return $out;
    }
}
