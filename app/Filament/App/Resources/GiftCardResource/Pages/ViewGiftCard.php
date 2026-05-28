<?php

namespace App\Filament\App\Resources\GiftCardResource\Pages;

use App\Filament\App\Resources\GiftCardResource;
use App\Models\GiftCard;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewGiftCard extends ViewRecord
{
    protected static string $resource = GiftCardResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Tarjeta')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('code')
                        ->label('Código')
                        ->copyable()
                        ->fontFamily('mono')
                        ->weight('bold')
                        ->size('lg'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            GiftCard::STATUS_ACTIVE => 'Activa',
                            GiftCard::STATUS_FULLY_REDEEMED => 'Redimida totalmente',
                            GiftCard::STATUS_EXPIRED => 'Expirada',
                            GiftCard::STATUS_CANCELLED => 'Cancelada',
                        })
                        ->color(fn (string $state) => match ($state) {
                            GiftCard::STATUS_ACTIVE => 'success',
                            GiftCard::STATUS_FULLY_REDEEMED => 'gray',
                            default => 'danger',
                        }),

                    Infolists\Components\TextEntry::make('expires_at')
                        ->label('Vence')
                        ->date('d/m/Y')
                        ->placeholder('Sin expiración'),
                ]),

            Infolists\Components\Section::make('Saldo')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('initial_balance')
                        ->label('Saldo inicial')
                        ->money('COP'),

                    Infolists\Components\TextEntry::make('current_balance')
                        ->label('Saldo actual')
                        ->money('COP')
                        ->weight('bold')
                        ->size('lg')
                        ->color(fn ($state) => (float) $state > 0 ? 'success' : 'gray'),

                    Infolists\Components\TextEntry::make('redeemed')
                        ->label('Redimido')
                        ->state(fn ($record) => '$' . number_format(
                            (float) $record->initial_balance - (float) $record->current_balance,
                            0, ',', '.'
                        )),
                ]),

            Infolists\Components\Section::make('Emisión')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('issued_at')
                        ->label('Emitida el')
                        ->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('issuedBy.name')
                        ->label('Vendida por'),
                    Infolists\Components\TextEntry::make('last_redeemed_at')
                        ->label('Última redención')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('Sin redenciones aún'),
                    Infolists\Components\TextEntry::make('issuedViaSale.invoice_number')
                        ->label('Factura de venta')
                        ->placeholder('Emitida desde admin (sin factura)'),
                ]),

            Infolists\Components\Section::make('Destinatario')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('recipient_name')->label('Nombre')->placeholder('—'),
                    Infolists\Components\TextEntry::make('sender_name')->label('De parte de')->placeholder('—'),
                    Infolists\Components\TextEntry::make('recipient_email')->label('Email')->placeholder('—'),
                    Infolists\Components\TextEntry::make('recipient_phone')->label('Teléfono')->placeholder('—'),
                ])
                ->collapsible()
                ->collapsed(fn ($record) => empty($record->recipient_name)),

            Infolists\Components\Section::make('Notas')
                ->visible(fn ($record) => ! empty($record->notes))
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label('')->columnSpanFull(),
                ]),
        ]);
    }
}
