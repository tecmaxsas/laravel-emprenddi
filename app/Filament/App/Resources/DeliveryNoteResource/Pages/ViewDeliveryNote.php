<?php

namespace App\Filament\App\Resources\DeliveryNoteResource\Pages;

use App\Filament\App\Resources\DeliveryNoteResource;
use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\DeliveryNote;
use App\Services\Sales\DeliveryNoteEngine;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    public function getTitle(): string
    {
        return 'Remisión '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (DeliveryNote $r) => $r->isDraft()),

            Actions\Action::make('dispatch')
                ->label('Despachar')
                ->icon('heroicon-o-truck')
                ->color('info')
                ->visible(fn (DeliveryNote $r) => $r->canBeDispatched()
                    && auth()->user()?->can('delivery_notes.dispatch'))
                ->requiresConfirmation()
                ->modalHeading('Despachar mercancía')
                ->modalDescription(fn (DeliveryNote $r) => sprintf(
                    'Se descargarán %d productos del inventario en %s. Esta acción no se puede revertir desde el sistema — para devolver mercancía usa una nota crédito o ajuste de inventario.',
                    $r->lines->count(),
                    $r->location?->fullName(),
                ))
                ->modalSubmitActionLabel('Despachar')
                ->action(function (DeliveryNote $r) {
                    try {
                        app(DeliveryNoteEngine::class)->dispatch($r);
                        Notification::make()
                            ->title('Remisión despachada')
                            ->body('Inventario actualizado.')
                            ->success()
                            ->send();
                        $this->refreshFormData(['status', 'dispatched_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error al despachar')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),

            Actions\Action::make('markDelivered')
                ->label('Marcar entregada')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (DeliveryNote $r) => $r->isDispatched()
                    && auth()->user()?->can('delivery_notes.manage'))
                ->requiresConfirmation()
                ->action(function (DeliveryNote $r) {
                    try {
                        app(DeliveryNoteEngine::class)->markDelivered($r);
                        Notification::make()->title('Marcada como entregada')->success()->send();
                        $this->refreshFormData(['status', 'delivered_at']);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('cancel')
                ->label('Cancelar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (DeliveryNote $r) => $r->isDraft()
                    && auth()->user()?->can('delivery_notes.manage'))
                ->requiresConfirmation()
                ->action(function (DeliveryNote $r) {
                    try {
                        app(DeliveryNoteEngine::class)->cancel($r);
                        Notification::make()->title('Remisión cancelada')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('convertToInvoice')
                ->label('Convertir a factura')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn (DeliveryNote $r) => $r->canBeBilled()
                    && auth()->user()?->can('delivery_notes.bill'))
                ->requiresConfirmation()
                ->modalHeading('Generar factura desde remisión')
                ->modalDescription(fn (DeliveryNote $r) => sprintf(
                    'Se creará una factura draft con las %d líneas de esta remisión. La factura NO volverá a descargar inventario (ya pasó al despachar) — solo asentará la venta y el COGS al postearse.',
                    $r->lines->count(),
                ))
                ->modalSubmitActionLabel('Convertir')
                ->action(function (DeliveryNote $r) {
                    try {
                        $invoice = app(DeliveryNoteEngine::class)->convertToInvoice($r);
                        Notification::make()
                            ->title('Factura creada — '.$invoice->fullNumber())
                            ->body('Remisión marcada como facturada.')
                            ->success()
                            ->send();
                        $this->redirect(SaleInvoiceResource::getUrl('edit', ['record' => $invoice]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error al convertir')
                            ->body($e->getMessage())
                            ->danger()
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
                        ->state(fn (DeliveryNote $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => DeliveryNote::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'draft' => 'gray',
                            'dispatched' => 'info',
                            'delivered' => 'success',
                            'cancelled' => 'danger',
                            'billed' => 'primary',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('seller.name')->label('Vendedor')->placeholder('—'),
                    Infolists\Components\TextEntry::make('customer.name')->label('Cliente')->columnSpan(2),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('dispatchedBy.name')->label('Despachada por')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Transporte')
                ->visible(fn (DeliveryNote $r) => $r->carrier || $r->vehicle_plate || $r->driver_name || $r->destination_address)
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('carrier')->label('Transportador')->placeholder('—'),
                    Infolists\Components\TextEntry::make('vehicle_plate')->label('Placa')->placeholder('—'),
                    Infolists\Components\TextEntry::make('driver_name')->label('Conductor')->placeholder('—'),
                    Infolists\Components\TextEntry::make('destination_address')->label('Destino')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('product.code')
                                ->label('SKU')->fontFamily('mono')->placeholder('—')->columnSpan(2),
                            Infolists\Components\TextEntry::make('description')->label('Descripción')->columnSpan(5),
                            Infolists\Components\TextEntry::make('quantity')->label('Cant.')->numeric(decimalPlaces: 2)->columnSpan(2),
                            Infolists\Components\TextEntry::make('unit_price')->label('Precio ref.')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(13),
                ]),

            Infolists\Components\Section::make('Despacho y entrega')
                ->visible(fn (DeliveryNote $r) => $r->dispatched_at || $r->delivered_at)
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('dispatched_at')->label('Despachada')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivered_at')->label('Entregada')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Facturación')
                ->visible(fn (DeliveryNote $r) => $r->isBilled())
                ->schema([
                    Infolists\Components\TextEntry::make('billedAtSaleInvoice.full_number')
                        ->label('Factura generada')
                        ->state(fn (DeliveryNote $r) => $r->billedAtSaleInvoice?->fullNumber())
                        ->url(fn (DeliveryNote $r) => $r->billedAtSaleInvoice
                            ? SaleInvoiceResource::getUrl('view', ['record' => $r->billedAtSaleInvoice])
                            : null),
                    Infolists\Components\TextEntry::make('billed_at')->label('Facturada el')->dateTime('Y-m-d H:i:s'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (DeliveryNote $r) => ! empty($r->notes))
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
