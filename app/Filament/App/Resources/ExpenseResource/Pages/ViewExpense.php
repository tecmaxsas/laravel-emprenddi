<?php

namespace App\Filament\App\Resources\ExpenseResource\Pages;

use App\Filament\App\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\ExpenseEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    public function getTitle(): string
    {
        return 'Gasto '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (Expense $record) => $record->status === Expense::STATUS_DRAFT),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Expense $record) => $record->status === Expense::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalHeading('Contabilizar gasto')
                ->modalDescription(fn (Expense $record) => sprintf(
                    'Vas a contabilizar el gasto %s por $%s. Se generará el asiento DR %s / CR %s.',
                    $record->fullNumber(),
                    number_format((float) $record->total, 2),
                    $record->expenseAccount?->code ?? '5XXX',
                    $record->paymentAccount?->code ?? '11XX',
                ))
                ->action(function (Expense $record) {
                    try {
                        app(ExpenseEngine::class)->post($record);
                        Notification::make()->title('Gasto contabilizado')->success()->send();
                        $this->redirect(ExpenseResource::getUrl('view', ['record' => $record]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Expense $record) => $record->status === Expense::STATUS_POSTED)
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Motivo de anulación')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (Expense $record, array $data) {
                    try {
                        app(ExpenseEngine::class)->cancel($record, $data['reason']);
                        Notification::make()->title('Gasto anulado')->success()->send();
                        $this->redirect(ExpenseResource::getUrl('view', ['record' => $record]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Datos')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Número')
                        ->state(fn (Expense $record) => $record->fullNumber())
                        ->weight('bold'),

                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => Expense::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'draft' => 'gray',
                            'posted' => 'success',
                            'cancelled' => 'danger',
                        }),

                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),

                    Infolists\Components\TextEntry::make('concept')
                        ->label('Concepto')
                        ->columnSpan(2)
                        ->weight('semibold'),

                    Infolists\Components\TextEntry::make('supplier.name')
                        ->label('Proveedor')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('supplier_invoice_number')
                        ->label('N° factura proveedor')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('description')
                        ->label('Descripción')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Imputación contable')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('expenseAccount')
                        ->label('Cuenta de gasto (DR)')
                        ->state(fn (Expense $record) => $record->expenseAccount?->fullName()),
                    Infolists\Components\TextEntry::make('paymentAccount')
                        ->label('Cuenta de pago (CR)')
                        ->state(fn (Expense $record) => $record->paymentAccount?->fullName()),
                ]),

            Infolists\Components\Section::make('Montos')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_amount')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('bold'),
                    Infolists\Components\TextEntry::make('payment_method')
                        ->label('Método de pago')
                        ->formatStateUsing(fn (string $state) => [
                            'cash' => 'Efectivo',
                            'bank_transfer' => 'Transferencia bancaria',
                            'check' => 'Cheque',
                            'credit_card' => 'Tarjeta de crédito',
                            'debit_card' => 'Tarjeta débito',
                            'electronic' => 'PSE / Pago electrónico',
                            'other' => 'Otro',
                        ][$state] ?? $state)
                        ->badge(),
                    Infolists\Components\TextEntry::make('reference')
                        ->label('Referencia')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Asiento contable')
                ->visible(fn (Expense $record) => $record->journal_entry_id)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (Expense $record) => $record->journalEntry?->fullNumber()),
                    Infolists\Components\TextEntry::make('posted_at')->label('Contabilizado')->dateTime(),
                    Infolists\Components\TextEntry::make('postedBy.name')->label('Por'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (Expense $record) => $record->notes)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label('')->columnSpanFull(),
                ]),
        ]);
    }
}
