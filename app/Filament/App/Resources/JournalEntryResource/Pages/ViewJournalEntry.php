<?php

namespace App\Filament\App\Resources\JournalEntryResource\Pages;

use App\Filament\App\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    public function getTitle(): string
    {
        return 'Asiento '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (JournalEntry $record) => $record->status === 'draft'),
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
                        ->state(fn (JournalEntry $record) => $record->fullNumber())
                        ->fontFamily('mono')
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('type')
                        ->label('Tipo')
                        ->formatStateUsing(fn (string $state) => JournalEntry::TYPES[$state] ?? $state)
                        ->badge(),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => JournalEntry::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'posted' => 'success',
                            'cancelled' => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('reference')->label('Referencia')->placeholder('—'),
                    Infolists\Components\TextEntry::make('thirdParty.name')->label('Tercero')->placeholder('—'),
                    Infolists\Components\TextEntry::make('description')->label('Concepto')
                        ->columnSpanFull()->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('account')
                                ->label('Cuenta')
                                ->state(fn ($record) => $record->account
                                    ? "{$record->account->code} — {$record->account->name}"
                                    : '—')
                                ->fontFamily('mono')
                                ->columnSpan(4),
                            Infolists\Components\TextEntry::make('thirdParty.name')
                                ->label('Tercero')
                                ->placeholder('—')
                                ->columnSpan(3),
                            Infolists\Components\TextEntry::make('description')
                                ->label('Detalle')
                                ->placeholder('—')
                                ->columnSpan(3),
                            Infolists\Components\TextEntry::make('debit')
                                ->label('Débito')
                                ->money('COP')
                                ->columnSpan(1),
                            Infolists\Components\TextEntry::make('credit')
                                ->label('Crédito')
                                ->money('COP')
                                ->columnSpan(1),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('total_debit')
                        ->label('Total débito')->money('COP')->weight('bold'),
                    Infolists\Components\TextEntry::make('total_credit')
                        ->label('Total crédito')->money('COP')->weight('bold'),
                    Infolists\Components\TextEntry::make('balance_check')
                        ->label('Balance')
                        ->state(fn (JournalEntry $record) => $record->isBalanced() ? '✅ Cuadrado' : '⚠️ Descuadrado'),
                ]),

            Infolists\Components\Section::make('Auditoría')
                ->columns(2)
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('createdBy.email')->label('Creado por')->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label('Creado el')->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('postedBy.email')->label('Contabilizado por')->placeholder('—'),
                    Infolists\Components\TextEntry::make('posted_at')->label('Contabilizado el')->dateTime('Y-m-d H:i')->placeholder('—'),
                ]),
        ]);
    }
}
