<?php

namespace App\Filament\App\Resources\QuotationResource\Pages;

use App\Filament\App\Resources\QuotationResource;
use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\Quotation;
use App\Services\Sales\QuotationConverter;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    public function getTitle(): string
    {
        return 'Cotización '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (Quotation $record) => $record->isDraft()),

            Actions\Action::make('markSent')
                ->label('Marcar enviada')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn (Quotation $record) => $record->isDraft()
                    && auth()->user()?->can('quotations.manage'))
                ->requiresConfirmation()
                ->action(function (Quotation $record) {
                    $record->update(['status' => Quotation::STATUS_SENT]);
                    Notification::make()->title('Cotización marcada como enviada')->success()->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('markApproved')
                ->label('Aprobar')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (Quotation $record) => $record->isSent()
                    && auth()->user()?->can('quotations.manage'))
                ->requiresConfirmation()
                ->action(function (Quotation $record) {
                    $record->update(['status' => Quotation::STATUS_APPROVED]);
                    Notification::make()->title('Cotización aprobada')->success()->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('markRejected')
                ->label('Rechazar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Quotation $record) => in_array($record->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)
                    && auth()->user()?->can('quotations.manage'))
                ->requiresConfirmation()
                ->action(function (Quotation $record) {
                    $record->update(['status' => Quotation::STATUS_REJECTED]);
                    Notification::make()->title('Cotización rechazada')->success()->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('convertToInvoice')
                ->label('Convertir a factura')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn (Quotation $record) => $record->canBeConverted()
                    && auth()->user()?->can('quotations.convert'))
                ->requiresConfirmation()
                ->modalHeading('Convertir a factura de venta')
                ->modalDescription(fn (Quotation $record) => sprintf(
                    'Se creará una factura draft con las %d líneas de esta cotización por $%s. Después podrás postearla por el flujo normal (asiento + inventario + DIAN).',
                    $record->lines->count(),
                    number_format((float) $record->total, 2),
                ))
                ->modalSubmitActionLabel('Convertir')
                ->action(function (Quotation $record) {
                    try {
                        $invoice = app(QuotationConverter::class)->convert($record);
                        Notification::make()
                            ->title('Factura creada — '.$invoice->fullNumber())
                            ->body('Cotización marcada como convertida.')
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
                        ->state(fn (Quotation $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('valid_until')
                        ->label('Vence')
                        ->date('Y-m-d')
                        ->placeholder('—')
                        ->color(fn (Quotation $r) => $r->isEffectivelyExpired() ? 'danger' : null),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (Quotation $r) => $r->isEffectivelyExpired()
                            ? 'Vencida'
                            : (Quotation::STATUSES[$r->status] ?? $r->status))
                        ->badge()
                        ->color(fn (Quotation $r) => match (true) {
                            $r->isEffectivelyExpired() => 'danger',
                            $r->status === Quotation::STATUS_DRAFT => 'gray',
                            $r->status === Quotation::STATUS_SENT => 'info',
                            $r->status === Quotation::STATUS_APPROVED => 'success',
                            $r->status === Quotation::STATUS_REJECTED => 'danger',
                            $r->status === Quotation::STATUS_CONVERTED => 'primary',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('customer.name')->label('Cliente')->columnSpan(2),
                    Infolists\Components\TextEntry::make('seller.name')->label('Vendedor')->placeholder('—'),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
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
                            Infolists\Components\TextEntry::make('unit_price')->label('Precio')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax.code')->label('Imp.')->placeholder('—')->columnSpan(1),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('discount_total')->label('Descuento')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('TOTAL')->money('COP')->weight('bold'),
                ]),

            Infolists\Components\Section::make('Conversión a factura')
                ->visible(fn (Quotation $r) => $r->isConverted())
                ->schema([
                    Infolists\Components\TextEntry::make('convertedTo.full_number')
                        ->label('Factura generada')
                        ->state(fn (Quotation $r) => $r->convertedTo?->fullNumber())
                        ->url(fn (Quotation $r) => $r->convertedTo
                            ? SaleInvoiceResource::getUrl('view', ['record' => $r->convertedTo])
                            : null),
                    Infolists\Components\TextEntry::make('converted_at')
                        ->label('Convertida el')
                        ->dateTime('Y-m-d H:i:s'),
                ]),

            Infolists\Components\Section::make('Términos y condiciones')
                ->visible(fn (Quotation $r) => ! empty($r->terms_and_conditions))
                ->schema([
                    Infolists\Components\TextEntry::make('terms_and_conditions')->label(''),
                ]),

            Infolists\Components\Section::make('Notas internas')
                ->visible(fn (Quotation $r) => ! empty($r->notes))
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
