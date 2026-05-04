<?php

namespace App\Filament\App\Resources\SaleInvoiceResource\Pages;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\SaleInvoice;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
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

            // En Iter 2 se agrega aquí Actions\Action::make('post') con
            // SaleInvoiceEngine::post() para generar asiento + salida de
            // inventario + reservar consecutivo DIAN.
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

            Infolists\Components\Section::make('Totales')
                ->columns(5)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('discount_total')->label('Descuento')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('TOTAL')->money('COP')->weight('bold'),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label('Estado pago')
                        ->formatStateUsing(fn (string $s) => SaleInvoice::PAYMENT_STATUSES[$s] ?? $s)
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
                        ->state(fn (SaleInvoice $r) => $r->balance)
                        ->money('COP')
                        ->weight('semibold'),
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
