<?php

namespace App\Filament\App\Resources\PayrollSettlementResource\Pages;

use App\Filament\App\Resources\PayrollSettlementResource;
use App\Models\Account;
use App\Models\PayrollSettlement;
use App\Services\Payroll\PayrollSettlementService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPayrollSettlement extends ViewRecord
{
    protected static string $resource = PayrollSettlementResource::class;

    public function getTitle(): string
    {
        return $this->record instanceof PayrollSettlement
            ? $this->record->typeLabel().' — '.($this->record->employee?->fullName() ?? '')
            : 'Liquidación';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (PayrollSettlement $record) => $record->isDraft()),

            Actions\Action::make('pay')
                ->label('Pagar / contabilizar')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (PayrollSettlement $record) => $record->isDraft()
                    && auth()->user()?->can('payroll.settlements.manage'))
                ->modalHeading('Pagar la liquidación')
                ->modalDescription('Se contabiliza el pago: débito a las prestaciones por pagar y crédito a la cuenta de caja/banco.')
                ->form([
                    Forms\Components\Select::make('account_id')
                        ->label('Cuenta de caja / banco')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '11%')
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'like', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)
                            ? Account::find($value)->code.' — '.Account::find($value)->name
                            : null),

                    Forms\Components\DatePicker::make('payment_date')
                        ->label('Fecha de pago')
                        ->default(now())
                        ->required(),
                ])
                ->action(function (array $data, PayrollSettlement $record) {
                    try {
                        $settlement = app(PayrollSettlementService::class)->pay(
                            $record,
                            (int) $data['account_id'],
                            $data['payment_date'],
                        );
                        Notification::make()
                            ->success()
                            ->title('Liquidación pagada')
                            ->body('Asiento '.$settlement->journalEntry?->fullNumber().' creado.')
                            ->send();
                        $this->refreshFormData(['status', 'payment_date', 'journal_entry_id']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('No se pudo pagar')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Datos de la liquidación')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('employee.full_name')
                        ->label('Empleado')
                        ->state(fn (PayrollSettlement $record) => $record->employee?->fullName())
                        ->weight('bold')
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('type')
                        ->label('Tipo')
                        ->formatStateUsing(fn (string $state) => PayrollSettlement::TYPES[$state] ?? $state)
                        ->badge(),
                    Infolists\Components\TextEntry::make('period_start')->label('Desde')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('period_end')->label('Hasta')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('worked_days')->label('Días liquidados'),
                    Infolists\Components\TextEntry::make('monthly_salary')->label('Salario')->money('COP'),
                    Infolists\Components\TextEntry::make('base')->label('Base (salario + auxilio)')->money('COP'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => PayrollSettlement::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => $state === PayrollSettlement::STATUS_PAID ? 'success' : 'gray'),
                ]),

            Infolists\Components\Section::make('Componentes')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('amount_cesantias')->label('Cesantías')->money('COP'),
                    Infolists\Components\TextEntry::make('amount_interest')->label('Intereses')->money('COP'),
                    Infolists\Components\TextEntry::make('amount_prima')->label('Prima')->money('COP'),
                    Infolists\Components\TextEntry::make('amount_vacaciones')->label('Vacaciones')->money('COP'),
                    Infolists\Components\TextEntry::make('amount_indemnizacion')
                        ->label('Indemnización')
                        ->money('COP')
                        ->visible(fn (PayrollSettlement $record) => (float) $record->amount_indemnizacion > 0),
                    Infolists\Components\TextEntry::make('amount')->label('TOTAL')->money('COP')->weight('bold'),
                ]),

            Infolists\Components\Section::make('Pago')
                ->visible(fn (PayrollSettlement $record) => $record->isPaid())
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('payment_date')->label('Fecha de pago')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento contable')
                        ->state(fn (PayrollSettlement $record) => $record->journalEntry?->fullNumber())
                        ->url(fn (PayrollSettlement $record) => $record->journalEntry
                            ? route('filament.app.resources.journal-entries.view', $record->journalEntry)
                            : null),
                ]),

            Infolists\Components\Section::make('Observaciones')
                ->visible(fn (PayrollSettlement $record) => $record->notes !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
