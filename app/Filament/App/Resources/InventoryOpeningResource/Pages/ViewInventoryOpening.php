<?php

namespace App\Filament\App\Resources\InventoryOpeningResource\Pages;

use App\Filament\App\Resources\InventoryOpeningResource;
use App\Models\InventoryOpening;
use App\Services\Inventory\InventoryOpeningEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryOpening extends ViewRecord
{
    protected static string $resource = InventoryOpeningResource::class;

    public function getTitle(): string
    {
        return 'Apertura '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->visible(fn (InventoryOpening $r) => $r->isDraft()),

            Actions\Action::make('post')
                ->label('Contabilizar apertura')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (InventoryOpening $r) => $r->isDraft()
                    && auth()->user()?->can('inventory.adjust'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar apertura de inventario')
                ->modalDescription(fn (InventoryOpening $r) => sprintf(
                    'Vas a cargar %d producto(s) como saldo inicial en %s. Se generarán los movimientos opening y el asiento DR Inventario / CR %s.',
                    $r->lines()->count(),
                    $r->location?->name,
                    $r->counterpartAccount?->code ?? '???',
                ))
                ->action(function (InventoryOpening $r) {
                    try {
                        app(InventoryOpeningEngine::class)->post($r);
                        Notification::make()->title('Apertura contabilizada')->success()->send();
                        $this->redirect(InventoryOpeningResource::getUrl('view', ['record' => $r]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),

            Actions\Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (InventoryOpening $r) => $r->isPosted())
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Motivo de anulación')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (InventoryOpening $r, array $data) {
                    try {
                        app(InventoryOpeningEngine::class)->cancel($r, $data['reason']);
                        Notification::make()->title('Apertura anulada')->success()->send();
                        $this->redirect(InventoryOpeningResource::getUrl('view', ['record' => $r]));
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
                        ->state(fn (InventoryOpening $r) => $r->fullNumber())
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => InventoryOpening::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'draft' => 'gray',
                            'posted' => 'success',
                            'cancelled' => 'danger',
                            default => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('counterpartAccount')
                        ->label('Cuenta contraparte (CR)')
                        ->state(fn (InventoryOpening $r) => $r->counterpartAccount?->fullName())
                        ->columnSpan(2),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->columns(6)
                        ->schema([
                            Infolists\Components\TextEntry::make('line_number')->label('#')->columnSpan(1),
                            Infolists\Components\TextEntry::make('product')
                                ->label('Producto')
                                ->state(fn ($record) => $record->product?->code.' — '.$record->product?->name)
                                ->columnSpan(3),
                            Infolists\Components\TextEntry::make('quantity')->label('Cant.')->numeric()->columnSpan(1),
                            Infolists\Components\TextEntry::make('unit_cost')->label('Costo unit.')->money('COP')->columnSpan(1),
                        ]),
                ]),

            Infolists\Components\Section::make('Asiento')
                ->visible(fn (InventoryOpening $r) => $r->journal_entry_id)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (InventoryOpening $r) => $r->journalEntry?->fullNumber()),
                    Infolists\Components\TextEntry::make('posted_at')->label('Contabilizado')->dateTime(),
                    Infolists\Components\TextEntry::make('postedBy.name')->label('Por'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (InventoryOpening $r) => $r->notes)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label('')->columnSpanFull(),
                ]),
        ]);
    }
}
