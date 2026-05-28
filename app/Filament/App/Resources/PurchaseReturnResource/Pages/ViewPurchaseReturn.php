<?php

namespace App\Filament\App\Resources\PurchaseReturnResource\Pages;

use App\Filament\App\Resources\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use App\Services\Purchases\PurchaseReturnEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseReturn extends ViewRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    public function getTitle(): string
    {
        return 'Devolución '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->visible(fn (PurchaseReturn $r) => $r->isDraft()),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (PurchaseReturn $r) => $r->isDraft()
                    && auth()->user()?->can('purchases.create'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar devolución a proveedor')
                ->modalDescription(fn (PurchaseReturn $r) => sprintf(
                    'Vas a devolver %d producto(s) por $%s a %s. Se generarán movimientos de salida de inventario y el asiento DR CxP / CR Inventario + IVA descontable.',
                    $r->lines()->count(),
                    number_format((float) $r->total, 2),
                    $r->supplier?->name,
                ))
                ->action(function (PurchaseReturn $r) {
                    try {
                        app(PurchaseReturnEngine::class)->post($r);
                        Notification::make()->title('Devolución contabilizada')->success()->send();
                        $this->redirect(PurchaseReturnResource::getUrl('view', ['record' => $r]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),

            Actions\Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (PurchaseReturn $r) => $r->isPosted())
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Motivo de anulación')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (PurchaseReturn $r, array $data) {
                    try {
                        app(PurchaseReturnEngine::class)->cancel($r, $data['reason']);
                        Notification::make()->title('Devolución anulada')->success()->send();
                        $this->redirect(PurchaseReturnResource::getUrl('view', ['record' => $r]));
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
                        ->state(fn (PurchaseReturn $r) => $r->fullNumber())
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => PurchaseReturn::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'draft' => 'gray',
                            'posted' => 'success',
                            'cancelled' => 'danger',
                        }),

                    Infolists\Components\TextEntry::make('supplier.name')->label('Proveedor')->columnSpan(2),
                    Infolists\Components\TextEntry::make('purchaseInvoice.full_number')
                        ->label('Factura origen')
                        ->state(fn (PurchaseReturn $r) => $r->purchaseInvoice?->fullNumber())
                        ->placeholder('—')
                        ->columnSpan(2),

                    Infolists\Components\TextEntry::make('reason')
                        ->label('Motivo')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->columns(7)
                        ->schema([
                            Infolists\Components\TextEntry::make('line_number')->label('#')->columnSpan(1),
                            Infolists\Components\TextEntry::make('product')
                                ->label('Producto')
                                ->state(fn ($record) => $record->product?->code.' — '.$record->description)
                                ->columnSpan(3),
                            Infolists\Components\TextEntry::make('quantity')->label('Cant.')->numeric()->columnSpan(1),
                            Infolists\Components\TextEntry::make('unit_cost')->label('Costo')->money('COP')->columnSpan(1),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(1),
                        ]),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA descontable a reversar')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('Total devuelto')->money('COP')->weight('bold'),
                ]),

            Infolists\Components\Section::make('Asiento')
                ->visible(fn (PurchaseReturn $r) => $r->journal_entry_id)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (PurchaseReturn $r) => $r->journalEntry?->fullNumber()),
                    Infolists\Components\TextEntry::make('posted_at')->label('Contabilizado')->dateTime(),
                    Infolists\Components\TextEntry::make('postedBy.name')->label('Por'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (PurchaseReturn $r) => $r->notes)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label('')->columnSpanFull(),
                ]),
        ]);
    }
}
