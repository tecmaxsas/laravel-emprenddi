<?php

namespace App\Filament\App\Resources\FixedAssetResource\Pages;

use App\Filament\App\Resources\FixedAssetResource;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Services\Assets\FixedAssetEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewFixedAsset extends ViewRecord
{
    protected static string $resource = FixedAssetResource::class;

    public function getTitle(): string
    {
        return $this->record->code.' — '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (FixedAsset $r) => $r->isActive() && $r->depreciations()->count() === 0),

            Actions\Action::make('dispose')
                ->label('Dar de baja / vender')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (FixedAsset $r) => $r->isActive())
                ->form([
                    Forms\Components\DatePicker::make('disposal_date')
                        ->label('Fecha de baja')
                        ->required()
                        ->default(now()),
                    Forms\Components\TextInput::make('sale_price')
                        ->label('Precio de venta')
                        ->numeric()
                        ->prefix('$')
                        ->default(0)
                        ->minValue(0)
                        ->helperText('0 si es baja sin recuperación.')
                        ->live(),
                    Forms\Components\Select::make('cash_account_id')
                        ->label('Cuenta de caja/banco')
                        ->placeholder('No aplica (sin venta)')
                        ->searchable()
                        ->visible(fn (Forms\Get $get) => (float) ($get('sale_price') ?? 0) > 0)
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '11%')
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)?->fullName()),
                    Forms\Components\Select::make('gain_loss_account_id')
                        ->label('Cuenta de ganancia / pérdida')
                        ->required()
                        ->searchable()
                        ->helperText('Si vende por encima del valor en libros: 4250 Otros ingresos. Si por debajo: 5310 Pérdida en venta de activos.')
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)?->fullName()),
                    Forms\Components\Textarea::make('notes')
                        ->label('Notas de la baja')
                        ->rows(2),
                ])
                ->action(function (FixedAsset $r, array $data) {
                    try {
                        app(FixedAssetEngine::class)->dispose(
                            $r,
                            $data['disposal_date'],
                            (float) ($data['sale_price'] ?? 0),
                            $data['cash_account_id'] ?? null,
                            (int) $data['gain_loss_account_id'],
                            $data['notes'] ?? null,
                        );
                        Notification::make()->title('Activo dado de baja')->success()->send();
                        $this->redirect(FixedAssetResource::getUrl('view', ['record' => $r]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identificación')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('code')->label('Código')->weight('bold'),
                    Infolists\Components\TextEntry::make('category')
                        ->label('Categoría')
                        ->formatStateUsing(fn (?string $s) => FixedAsset::CATEGORIES[$s] ?? '—')
                        ->badge(),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $s) => FixedAsset::STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => $s === 'active' ? 'success' : 'gray'),

                    Infolists\Components\TextEntry::make('name')->label('Nombre')->columnSpanFull()->weight('semibold'),
                    Infolists\Components\TextEntry::make('description')->label('Descripción')->placeholder('—')->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Compra y vida útil')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('purchased_at')->label('Fecha compra')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('activated_at')->label('Inicio depreciación')->date('Y-m-d')->placeholder('No iniciada'),
                    Infolists\Components\TextEntry::make('useful_life_months')->label('Vida útil (meses)'),
                    Infolists\Components\TextEntry::make('depreciation_method')->label('Método'),
                ]),

            Infolists\Components\Section::make('Valoración')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('cost')->label('Costo')->money('COP')->weight('semibold'),
                    Infolists\Components\TextEntry::make('residual_value')->label('Valor residual')->money('COP'),
                    Infolists\Components\TextEntry::make('accumulated_depreciation')
                        ->label('Depreciación acumulada')->money('COP'),
                    Infolists\Components\TextEntry::make('book_value')
                        ->label('Valor en libros')
                        ->state(fn (FixedAsset $r) => $r->bookValue())
                        ->money('COP')
                        ->weight('bold')
                        ->color('primary'),
                ]),

            Infolists\Components\Section::make('Cuentas')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('assetAccount')
                        ->label('Activo')
                        ->state(fn (FixedAsset $r) => $r->assetAccount?->fullName()),
                    Infolists\Components\TextEntry::make('depreciationAccount')
                        ->label('Depreciación acumulada')
                        ->state(fn (FixedAsset $r) => $r->depreciationAccount?->fullName()),
                    Infolists\Components\TextEntry::make('depreciationExpenseAccount')
                        ->label('Gasto depreciación')
                        ->state(fn (FixedAsset $r) => $r->depreciationExpenseAccount?->fullName()),
                ]),

            Infolists\Components\Section::make('Historial de depreciaciones')
                ->visible(fn (FixedAsset $r) => $r->depreciations()->count() > 0)
                ->schema([
                    Infolists\Components\RepeatableEntry::make('depreciations')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            Infolists\Components\TextEntry::make('period')
                                ->label('Período')
                                ->state(fn ($record) => $record->periodLabel()),
                            Infolists\Components\TextEntry::make('amount')->label('Monto')->money('COP'),
                            Infolists\Components\TextEntry::make('journalEntry.full_number')
                                ->label('Asiento')
                                ->state(fn ($record) => $record->journalEntry?->fullNumber())
                                ->fontFamily('mono'),
                            Infolists\Components\TextEntry::make('created_at')->label('Generado')->dateTime('Y-m-d H:i'),
                        ]),
                ]),

            Infolists\Components\Section::make('Baja del activo')
                ->visible(fn (FixedAsset $r) => $r->isDisposed())
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('disposed_at')->label('Fecha de baja')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('disposal_sale_price')->label('Precio de venta')->money('COP'),
                    Infolists\Components\TextEntry::make('disposalJournalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (FixedAsset $r) => $r->disposalJournalEntry?->fullNumber()),
                    Infolists\Components\TextEntry::make('disposal_notes')->label('Notas')->columnSpanFull()->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (FixedAsset $r) => $r->notes)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label('')->columnSpanFull(),
                ]),
        ]);
    }
}
