<?php

namespace App\Filament\App\Resources\CommissionSettlementResource\Pages;

use App\Filament\App\Resources\CommissionSettlementResource;
use App\Models\CommissionSettlement;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewCommissionSettlement extends ViewRecord
{
    protected static string $resource = CommissionSettlementResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Liquidación')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('seller.name')
                        ->label('Vendedor')
                        ->formatStateUsing(fn ($record) => trim($record->seller->name.' '.$record->seller->last_name))
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('period_start')
                        ->label('Período')
                        ->state(fn ($record) => $record->period_start->format('d/m/Y').' — '.$record->period_end->format('d/m/Y')),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === CommissionSettlement::STATUS_PAID ? 'Liquidada' : 'Borrador')
                        ->color(fn (string $state) => $state === CommissionSettlement::STATUS_PAID ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('entries_count')->label('Ventas incluidas'),
                    Infolists\Components\TextEntry::make('total_amount')->label('Total comisión')->money('COP')->weight('bold')->size('lg'),
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento contable')
                        ->state(fn ($record) => $record->journalEntry?->fullNumber())
                        ->placeholder('—')
                        ->url(fn ($record) => $record->journalEntry
                            ? route('filament.app.resources.journal-entries.view', $record->journalEntry)
                            : null),
                    Infolists\Components\TextEntry::make('settled_at')->label('Liquidada el')->dateTime('d/m/Y H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('settledBy.name')->label('Liquidada por')->placeholder('—'),
                    Infolists\Components\TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
