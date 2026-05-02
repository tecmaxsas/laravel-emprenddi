<?php

namespace App\Filament\App\Resources\JournalEntryResource\Pages;

use App\Filament\App\Resources\JournalEntryResource;
use App\Models\Account;
use App\Models\Company;
use App\Services\Accounting\JournalEntryNumberer;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->validateLines($data['lines'] ?? []);

        $companyId = Auth::user()->company_id;
        $company = Company::findOrFail($companyId);
        $prefix = $data['prefix'] ?? 'AS';

        $data['company_id'] = $companyId;
        $data['number'] = app(JournalEntryNumberer::class)->next($company, $prefix);
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';
        $data['total_debit'] = collect($data['lines'] ?? [])->sum(fn ($l) => (float) ($l['debit'] ?? 0));
        $data['total_credit'] = collect($data['lines'] ?? [])->sum(fn ($l) => (float) ($l['credit'] ?? 0));

        // Asignar line_number secuencial y limpiar campos auxiliares
        $data['lines'] = array_values(array_map(function ($line, $i) {
            unset($line['_requires_third_party']);
            $line['line_number'] = $i + 1;

            return $line;
        }, $data['lines'] ?? [], array_keys($data['lines'] ?? [])));

        return $data;
    }

    protected function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'El asiento requiere al menos 2 líneas.']);
        }

        $errors = [];
        $debitSum = 0;
        $creditSum = 0;

        foreach ($lines as $i => $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit > 0 && $credit > 0) {
                $errors["lines.{$i}.debit"] = 'No puede tener débito y crédito en la misma línea.';
            }
            if ($debit == 0 && $credit == 0) {
                $errors["lines.{$i}.debit"] = 'La línea debe tener débito o crédito mayor a cero.';
            }

            $accountId = $line['account_id'] ?? null;
            if ($accountId) {
                $account = Account::withoutGlobalScopes()->find($accountId);
                if ($account && $account->requires_third_party && empty($line['third_party_id'])) {
                    $errors["lines.{$i}.third_party_id"] = "La cuenta {$account->code} exige tercero.";
                }
            }

            $debitSum += $debit;
            $creditSum += $credit;
        }

        if (abs($debitSum - $creditSum) >= 0.01) {
            $errors['lines'] = sprintf(
                'El asiento no cuadra: débito $%s vs crédito $%s (diferencia $%s).',
                number_format($debitSum, 2),
                number_format($creditSum, 2),
                number_format($debitSum - $creditSum, 2),
            );
        }

        if (! empty($errors)) {
            Notification::make()
                ->danger()
                ->title('Asiento inválido')
                ->body(reset($errors))
                ->send();
            throw ValidationException::withMessages($errors);
        }
    }
}
