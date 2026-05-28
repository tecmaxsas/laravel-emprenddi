<?php

namespace App\Filament\App\Resources\CommissionSettlementResource\Pages;

use App\Filament\App\Resources\CommissionSettlementResource;
use App\Models\CommissionEntry;
use App\Models\User;
use App\Services\Commissions\CommissionSettlementEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCommissionSettlements extends ListRecords
{
    protected static string $resource = CommissionSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Liquidar comisiones: elige vendedor + periodo, el engine reune
            // las entries pending, crea la liquidacion y el asiento contable.
            Actions\Action::make('settle')
                ->label('Liquidar comisiones')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn () => auth()->user()?->can('commissions.settle'))
                ->modalHeading('Liquidar comisiones de un vendedor')
                ->modalDescription('Reúne las comisiones pendientes del vendedor en el período, genera la liquidación y el asiento contable (gasto / por pagar).')
                ->form([
                    Forms\Components\Select::make('seller_user_id')
                        ->label('Vendedor')
                        ->required()
                        ->searchable()
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where('active', true)
                            ->orderBy('name')->get()
                            ->mapWithKeys(fn ($u) => [$u->id => trim($u->name.' '.$u->last_name)])->all())
                        ->live()
                        ->helperText(function (Forms\Get $get) {
                            $sellerId = $get('seller_user_id');
                            if (! $sellerId) return null;
                            $pending = CommissionEntry::query()
                                ->where('seller_user_id', $sellerId)
                                ->where('status', CommissionEntry::STATUS_PENDING)
                                ->sum('amount');
                            return 'Comisiones pendientes (total histórico): $'.number_format((float) $pending, 0, ',', '.');
                        }),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DatePicker::make('period_start')
                            ->label('Desde')
                            ->required()
                            ->default(now()->startOfMonth()),
                        Forms\Components\DatePicker::make('period_end')
                            ->label('Hasta')
                            ->required()
                            ->default(now()->endOfMonth()),
                    ]),
                    Forms\Components\Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    try {
                        $settlement = app(CommissionSettlementEngine::class)->settle(
                            (int) $data['seller_user_id'],
                            $data['period_start'],
                            $data['period_end'],
                            $data['notes'] ?? null,
                        );
                        Notification::make()
                            ->title('Comisiones liquidadas')
                            ->body(sprintf(
                                '%d ventas · $%s. Asiento %s generado.',
                                $settlement->entries_count,
                                number_format((float) $settlement->total_amount, 0, ',', '.'),
                                $settlement->journalEntry?->fullNumber() ?? '—',
                            ))
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo liquidar')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
