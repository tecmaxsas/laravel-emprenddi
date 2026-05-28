<?php

namespace App\Filament\App\Resources\ProductSerialResource\Pages;

use App\Filament\App\Resources\ProductSerialResource;
use App\Filament\App\Resources\WarrantyResource;
use App\Models\ProductSerial;
use App\Support\WarrantiesSettings;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * Vista de un serial — pensada como pantalla de garantía: el operador
 * busca por SN, ve qué producto es, cuándo entró, en qué venta salió
 * y a qué cliente, con links directos al recibo de compra y venta.
 */
class ViewProductSerial extends ViewRecord
{
    protected static string $resource = ProductSerialResource::class;

    public function getTitle(): string
    {
        return 'Serial '.$this->record->serial_number;
    }

    protected function getHeaderActions(): array
    {
        $record = $this->record;

        return [
            // Atajo a crear ticket de garantía precargando este serial.
            // Solo aparece si la feature está activa y el serial está sold.
            Actions\Action::make('createWarranty')
                ->label('Crear garantía')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->visible(fn () => WarrantiesSettings::enabled()
                    && $record->status === ProductSerial::STATUS_SOLD
                    && auth()->user()?->can('warranties.create'))
                ->url(fn () => WarrantyResource::getUrl('create').'?serial='.$record->id),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Producto')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('serial_number')
                        ->label('Número de serie')
                        ->fontFamily('mono')->weight('bold')->copyable(),
                    Infolists\Components\TextEntry::make('product.name')
                        ->label('Producto')
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('product.code')
                        ->label('SKU'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => ProductSerial::STATUSES[$state] ?? $state)
                        ->color(fn (string $state) => match ($state) {
                            ProductSerial::STATUS_IN_STOCK => 'success',
                            ProductSerial::STATUS_SOLD => 'info',
                            ProductSerial::STATUS_RETURNED => 'warning',
                            ProductSerial::STATUS_DEFECTIVE => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('location.name')
                        ->label('Sede actual')
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Origen — Compra')
                ->visible(fn (ProductSerial $r) => $r->purchase_invoice_line_id !== null)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('received_at')
                        ->label('Fecha de ingreso')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('purchaseLine.invoice.full_number')
                        ->label('Factura de compra')
                        ->state(fn (ProductSerial $r) => $r->purchaseLine?->invoice?->fullNumber())
                        ->url(fn (ProductSerial $r) => $r->purchaseLine?->invoice
                            ? route('filament.app.resources.purchase-invoices.view', $r->purchaseLine->invoice)
                            : null),
                    Infolists\Components\TextEntry::make('purchaseLine.invoice.supplier.name')
                        ->label('Proveedor')
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Venta — Garantía')
                ->visible(fn (ProductSerial $r) => $r->sale_invoice_line_id !== null)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('sold_at')
                        ->label('Fecha de venta')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('saleLine.invoice.full_number')
                        ->label('Factura de venta')
                        ->state(fn (ProductSerial $r) => $r->saleLine?->invoice?->fullNumber())
                        ->url(fn (ProductSerial $r) => $r->saleLine?->invoice
                            ? route('filament.app.resources.sale-invoices.view', $r->saleLine->invoice)
                            : null),
                    Infolists\Components\TextEntry::make('saleLine.invoice.customer.name')
                        ->label('Cliente')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('saleLine.invoice.customer.document_number')
                        ->label('Documento cliente')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('saleLine.invoice.customer.phone')
                        ->label('Teléfono cliente')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('saleLine.invoice.customer.email')
                        ->label('Email cliente')
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (ProductSerial $r) => $r->notes !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
