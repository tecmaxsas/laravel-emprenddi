<?php

namespace App\Filament\App\Resources\SaleInvoiceResource\Pages;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\SaleInvoice;
use App\Services\Sales\SaleInvoiceEngine;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSaleInvoice extends ViewRecord
{
    protected static string $resource = SaleInvoiceResource::class;

    public function getTitle(): string
    {
        return 'Factura '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (SaleInvoice $record) => $record->status === 'draft'),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (SaleInvoice $record) => $record->status === 'draft'
                    && auth()->user()?->can('sales.post'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar factura de venta')
                ->modalDescription(fn (SaleInvoice $record) => sprintf(
                    'Vas a contabilizar la factura %s por $%s. Se generará el asiento contable, salida de inventario y se reservará el consecutivo DIAN si la sede tiene resolución asignada. No podrás editarla después.',
                    $record->fullNumber(),
                    number_format((float) $record->total, 2),
                ))
                ->modalSubmitActionLabel('Contabilizar')
                ->action(function (SaleInvoice $record) {
                    try {
                        $invoice = app(SaleInvoiceEngine::class)->post($record);
                        Notification::make()
                            ->success()
                            ->title('Factura contabilizada')
                            ->body("Asiento {$invoice->journalEntry?->fullNumber()} creado. Número final: {$invoice->fullNumber()}.")
                            ->send();
                        $this->refreshFormData(['status', 'payment_status', 'journal_entry_id', 'prefix', 'number']);
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
                        ->state(fn (SaleInvoice $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('due_date')->label('Vence')->date('Y-m-d')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $s) => SaleInvoice::STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => match ($s) {
                            'posted' => 'success', 'cancelled' => 'danger', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('customer.name')->label('Cliente')->columnSpan(2),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('seller.name')->label('Vendedor')->placeholder('—'),
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
                            Infolists\Components\TextEntry::make('unit_price')->label('Precio unit.')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax.code')->label('Imp.')->placeholder('—')->columnSpan(1),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Retenciones')
                ->visible(fn (SaleInvoice $r) => (float) $r->retention_total > 0)
                ->schema([
                    Infolists\Components\RepeatableEntry::make('retentions')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('tax_code')->label('Código')->fontFamily('mono')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax_name')->label('Concepto')->columnSpan(5),
                            Infolists\Components\TextEntry::make('base_amount')->label('Base')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('rate')->label('%')->formatStateUsing(fn ($s) => number_format((float) $s, 4).' %')->columnSpan(1),
                            Infolists\Components\TextEntry::make('amount')->label('Retenido')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(6)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('discount_total')->label('Descuento')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('Total')->money('COP'),
                    Infolists\Components\TextEntry::make('retention_total')->label('Retenciones')->money('COP'),
                    Infolists\Components\TextEntry::make('net_payable')->label('NETO A PAGAR')->money('COP')->weight('bold'),
                ]),

            Infolists\Components\Section::make('Pagado / Saldo')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('paid_amount')->label('Pagado')->money('COP')->weight('semibold'),
                    Infolists\Components\TextEntry::make('balance')
                        ->label('Saldo pendiente')
                        ->state(fn (SaleInvoice $r) => $r->balance)
                        ->money('COP')
                        ->weight('semibold'),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label('Estado pago')
                        ->formatStateUsing(fn (string $s) => SaleInvoice::PAYMENT_STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => match ($s) {
                            'pendiente' => 'warning', 'parcial' => 'info', 'pagado' => 'success',
                            'vencido' => 'danger', 'cancelada' => 'gray', default => 'gray',
                        }),
                ]),

            Infolists\Components\Section::make('Asiento contable')
                ->visible(fn (SaleInvoice $r) => $r->journal_entry_id !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (SaleInvoice $r) => $r->journalEntry?->fullNumber())
                        ->url(fn (SaleInvoice $r) => $r->journalEntry
                            ? route('filament.app.resources.journal-entries.view', $r->journalEntry)
                            : null),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (SaleInvoice $r) => $r->notes !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
