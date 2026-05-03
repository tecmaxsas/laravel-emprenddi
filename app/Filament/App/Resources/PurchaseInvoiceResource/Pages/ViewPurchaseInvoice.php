<?php

namespace App\Filament\App\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\App\Resources\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseInvoiceEngine;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseInvoice extends ViewRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    public function getTitle(): string
    {
        return 'Factura '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (PurchaseInvoice $record) => $record->status === 'draft'),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (PurchaseInvoice $record) => $record->status === 'draft'
                    && auth()->user()?->can('purchases.post'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar factura de compra')
                ->modalDescription(fn (PurchaseInvoice $record) => sprintf(
                    'Vas a contabilizar la factura %s por $%s. Se generará el asiento contable y los movimientos de inventario. No podrás editarla después.',
                    $record->fullNumber(),
                    number_format((float) $record->total, 2),
                ))
                ->modalSubmitActionLabel('Contabilizar')
                ->action(function (PurchaseInvoice $record) {
                    try {
                        $invoice = app(PurchaseInvoiceEngine::class)->post($record);
                        Notification::make()
                            ->success()
                            ->title('Factura contabilizada')
                            ->body("Asiento {$invoice->journalEntry?->fullNumber()} creado.")
                            ->send();
                        $this->refreshFormData(['status', 'payment_status', 'journal_entry_id']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al contabilizar')
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
            Infolists\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Número')
                        ->state(fn (PurchaseInvoice $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('due_date')->label('Vence')->date('Y-m-d')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $s) => PurchaseInvoice::STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => match ($s) {
                            'posted' => 'success', 'cancelled' => 'danger', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('supplier.name')->label('Proveedor')->columnSpan(2),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('supplier_invoice_number')->label('N° proveedor')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('product.code')
                                ->label('SKU')->fontFamily('mono')
                                ->placeholder('—')->columnSpan(2),
                            Infolists\Components\TextEntry::make('description')->label('Descripción')->columnSpan(4),
                            Infolists\Components\TextEntry::make('quantity')->label('Cant.')->numeric(decimalPlaces: 2)->columnSpan(1),
                            Infolists\Components\TextEntry::make('unit_cost')->label('Costo unit.')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax.code')->label('Imp.')->placeholder('—')->columnSpan(1),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(5)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('discount_total')->label('Descuento')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('TOTAL')->money('COP')->weight('bold'),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label('Estado pago')
                        ->formatStateUsing(fn (string $s) => PurchaseInvoice::PAYMENT_STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => match ($s) {
                            'pendiente' => 'warning', 'parcial' => 'info', 'pagado' => 'success',
                            'vencido' => 'danger', 'cancelada' => 'gray', default => 'gray',
                        }),
                ]),

            Infolists\Components\Section::make('Pagado / Saldo')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('paid_amount')->label('Pagado')->money('COP')->weight('semibold'),
                    Infolists\Components\TextEntry::make('balance')
                        ->label('Saldo pendiente')
                        ->state(fn (PurchaseInvoice $r) => $r->balance)
                        ->money('COP')
                        ->weight('semibold'),
                ]),

            Infolists\Components\Section::make('Asiento contable')
                ->visible(fn (PurchaseInvoice $r) => $r->journal_entry_id !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (PurchaseInvoice $r) => $r->journalEntry?->fullNumber())
                        ->url(fn (PurchaseInvoice $r) => $r->journalEntry
                            ? route('filament.app.resources.journal-entries.view', $r->journalEntry)
                            : null),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (PurchaseInvoice $r) => $r->notes !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
