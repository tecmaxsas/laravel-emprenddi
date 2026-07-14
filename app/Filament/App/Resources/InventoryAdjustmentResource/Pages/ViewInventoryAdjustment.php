<?php

namespace App\Filament\App\Resources\InventoryAdjustmentResource\Pages;

use App\Filament\App\Resources\InventoryAdjustmentResource;
use App\Models\InventoryAdjustment;
use App\Services\Inventory\InventoryAdjustmentEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryAdjustment extends ViewRecord
{
    protected static string $resource = InventoryAdjustmentResource::class;

    public function getTitle(): string
    {
        return 'Ajuste '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->visible(fn (InventoryAdjustment $r) => $r->isDraft()),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (InventoryAdjustment $r) => $r->isDraft()
                    && auth()->user()?->can('inventory.adjust'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar ajuste de inventario')
                ->modalDescription(fn (InventoryAdjustment $r) => sprintf(
                    'Vas a registrar un ajuste de %s en %s con %d línea(s). Se generarán los movimientos de inventario y el asiento contable.',
                    InventoryAdjustment::DIRECTIONS[$r->direction] ?? $r->direction,
                    $r->location?->name,
                    $r->lines()->count(),
                ))
                ->action(function (InventoryAdjustment $r) {
                    try {
                        app(InventoryAdjustmentEngine::class)->post($r);
                        Notification::make()->title('Ajuste contabilizado')->success()->send();
                        $this->redirect(InventoryAdjustmentResource::getUrl('view', ['record' => $r]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),

            Actions\Action::make('printLabels')
                ->label('Imprimir etiquetas')
                ->icon('heroicon-o-qr-code')
                ->color('info')
                ->visible(fn (InventoryAdjustment $r) => \App\Support\LabelsSettings::enabled()
                    && $r->isPosted()
                    && $r->direction === 'in')
                ->modalHeading('Imprimir etiquetas de los productos ajustados')
                ->modalDescription('Se imprime una etiqueta por unidad ingresada al inventario. Aplica solo a ajustes de tipo "Entrada".')
                ->form([
                    Forms\Components\TextInput::make('multiplier')
                        ->label('Multiplicador de cantidad')
                        ->numeric()->minValue(1)->maxValue(100)->default(1)
                        ->required(),
                ])
                ->action(function (InventoryAdjustment $r, array $data, $livewire) {
                    $mult = max(1, (int) ($data['multiplier'] ?? 1));
                    $spec = $r->lines()
                        ->whereNotNull('product_id')
                        ->get(['product_id', 'quantity'])
                        ->map(fn ($l) => $l->product_id.':'.max(1, (int) round((float) $l->quantity) * $mult))
                        ->implode(',');
                    if (! $spec) {
                        Notification::make()->title('Sin productos con SKU')->warning()->send();
                        return;
                    }
                    $url = route('labels.print', ['products' => $spec]);
                    $livewire->js('window.open('.json_encode($url).", '_blank')");
                }),

            Actions\Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (InventoryAdjustment $r) => $r->isPosted())
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Motivo de anulación')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (InventoryAdjustment $r, array $data) {
                    try {
                        app(InventoryAdjustmentEngine::class)->cancel($r, $data['reason']);
                        Notification::make()->title('Ajuste anulado')->success()->send();
                        $this->redirect(InventoryAdjustmentResource::getUrl('view', ['record' => $r]));
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
                        ->state(fn (InventoryAdjustment $r) => $r->fullNumber())
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => InventoryAdjustment::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'draft' => 'gray',
                            'posted' => 'success',
                            'cancelled' => 'danger',
                        }),

                    Infolists\Components\TextEntry::make('direction')
                        ->label('Tipo')
                        ->formatStateUsing(fn (string $state) => InventoryAdjustment::DIRECTIONS[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => $state === 'in' ? 'success' : 'danger'),

                    Infolists\Components\TextEntry::make('reason_code')
                        ->label('Motivo')
                        ->formatStateUsing(fn (string $state) => InventoryAdjustment::REASONS[$state] ?? $state),

                    Infolists\Components\TextEntry::make('counterpartAccount')
                        ->label('Cuenta contraparte')
                        ->state(fn (InventoryAdjustment $r) => $r->counterpartAccount?->fullName())
                        ->columnSpan(2),

                    Infolists\Components\TextEntry::make('reason_description')
                        ->label('Descripción del motivo')
                        ->placeholder('—')
                        ->columnSpanFull(),
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
                ->visible(fn (InventoryAdjustment $r) => $r->journal_entry_id)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (InventoryAdjustment $r) => $r->journalEntry?->fullNumber()),
                    Infolists\Components\TextEntry::make('posted_at')->label('Contabilizado')->dateTime(),
                    Infolists\Components\TextEntry::make('postedBy.name')->label('Por'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (InventoryAdjustment $r) => $r->notes)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label('')->columnSpanFull(),
                ]),
        ]);
    }
}
