<?php

namespace App\Filament\App\Resources\InventoryTransferResource\Pages;

use App\Filament\App\Resources\InventoryTransferResource;
use App\Models\InventoryTransfer;
use App\Services\Inventory\InventoryTransferEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryTransfer extends ViewRecord
{
    protected static string $resource = InventoryTransferResource::class;

    public function getTitle(): string
    {
        return 'Transferencia '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (InventoryTransfer $r) => $r->isDraft()),

            Actions\Action::make('post')
                ->label('Contabilizar transferencia')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (InventoryTransfer $r) => $r->isDraft()
                    && auth()->user()?->can('inventory.transfer'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar transferencia')
                ->modalDescription(fn (InventoryTransfer $r) => sprintf(
                    'Vas a mover %d producto(s) desde %s hacia %s. Se generarán los movimientos de inventario y no podrás editar la transferencia después.',
                    $r->lines()->count(),
                    $r->fromLocation?->name,
                    $r->toLocation?->name,
                ))
                ->action(function (InventoryTransfer $r) {
                    try {
                        app(InventoryTransferEngine::class)->post($r);
                        Notification::make()->title('Transferencia contabilizada')->success()->send();
                        $this->redirect(InventoryTransferResource::getUrl('view', ['record' => $r]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),

            Actions\Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (InventoryTransfer $r) => $r->isPosted())
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Motivo de anulación')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (InventoryTransfer $r, array $data) {
                    try {
                        app(InventoryTransferEngine::class)->cancel($r, $data['reason']);
                        Notification::make()->title('Transferencia anulada')->success()->send();
                        $this->redirect(InventoryTransferResource::getUrl('view', ['record' => $r]));
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
                        ->state(fn (InventoryTransfer $r) => $r->fullNumber())
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $s) => InventoryTransfer::STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => match ($s) {
                            'draft' => 'gray',
                            'posted' => 'success',
                            'cancelled' => 'danger',
                        }),
                    Infolists\Components\TextEntry::make('posted_at')->label('Contabilizada')->dateTime()->placeholder('—'),

                    Infolists\Components\TextEntry::make('fromLocation.name')->label('Origen')->columnSpan(2),
                    Infolists\Components\TextEntry::make('toLocation.name')->label('Destino')->columnSpan(2),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->columns(6)
                        ->schema([
                            Infolists\Components\TextEntry::make('line_number')->label('#')->columnSpan(1),
                            Infolists\Components\TextEntry::make('product.fullName')
                                ->label('Producto')
                                ->state(fn ($record) => $record->product?->code.' — '.$record->product?->name)
                                ->columnSpan(3),
                            Infolists\Components\TextEntry::make('quantity')->label('Cant.')->numeric()->columnSpan(1),
                            Infolists\Components\TextEntry::make('unit_cost')->label('Costo unit.')->money('COP')->columnSpan(1),
                        ]),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (InventoryTransfer $r) => $r->notes)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label('')->columnSpanFull(),
                ]),
        ]);
    }
}
