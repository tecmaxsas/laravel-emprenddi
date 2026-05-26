<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\GiftCardResource\Pages;
use App\Filament\App\Resources\GiftCardResource\RelationManagers;
use App\Filament\Concerns\ChecksPermission;
use App\Models\GiftCard;
use App\Support\GiftCardsSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GiftCardResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'gift_cards.view'; }
    protected static function managePermission(): string { return 'gift_cards.issue'; }

    protected static ?string $model = GiftCard::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';
    protected static ?string $navigationLabel = 'Tarjetas Regalo';
    protected static ?string $modelLabel = 'Tarjeta Regalo';
    protected static ?string $pluralModelLabel = 'Tarjetas Regalo';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 85;

    public static function canAccess(): bool
    {
        if (! GiftCardsSettings::moduleActive()) return false;
        return parent::canAccess();
    }

    public static function form(Form $form): Form
    {
        // El form sirve para EMITIR una gift card desde el admin (sin pasar
        // por POS). La redencion es solo desde POS. Editar una gift card
        // existente solo permite cambiar notas/destinatario, no el saldo.
        return $form->schema([
            Forms\Components\Section::make('Datos de la tarjeta')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('Se genera automáticamente al guardar')
                        ->helperText('Formato GC-XXXXX-XXXXX. Único por empresa, sin caracteres ambiguos.')
                        ->visible(fn (string $operation) => $operation === 'edit'),

                    Forms\Components\TextInput::make('initial_balance')
                        ->label('Saldo inicial')
                        ->numeric()
                        ->minValue(1000)
                        ->prefix('$')
                        ->required()
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->dehydrated()
                        ->helperText('Valor de la tarjeta al momento de emitirla.'),

                    Forms\Components\TextInput::make('current_balance')
                        ->label('Saldo actual')
                        ->numeric()
                        ->prefix('$')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (string $operation) => $operation === 'edit'),

                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD', 'EUR' => 'EUR'])
                        ->default('COP')
                        ->required()
                        ->disabled(fn (string $operation) => $operation === 'edit'),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Fecha de expiración')
                        ->helperText(function () {
                            $months = (int) GiftCardsSettings::get('default_expiry_months');
                            return $months > 0
                                ? "Default según configuración: {$months} meses desde hoy. Déjalo vacío para que no expire."
                                : 'Déjalo vacío para que no expire.';
                        })
                        ->default(function () {
                            $months = (int) GiftCardsSettings::get('default_expiry_months');
                            return $months > 0 ? now()->addMonths($months) : null;
                        }),

                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->options([
                            GiftCard::STATUS_ACTIVE => 'Activa',
                            GiftCard::STATUS_FULLY_REDEEMED => 'Redimida totalmente',
                            GiftCard::STATUS_EXPIRED => 'Expirada',
                            GiftCard::STATUS_CANCELLED => 'Cancelada',
                        ])
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (string $operation) => $operation === 'edit'),
                ]),

            Forms\Components\Section::make('Destinatario y remitente')
                ->description('Información opcional para personalizar la tarjeta y enviarla.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('recipient_name')
                        ->label('Nombre del destinatario')
                        ->maxLength(150)
                        ->required(fn () => GiftCardsSettings::isEnabled('require_recipient_data')),

                    Forms\Components\TextInput::make('sender_name')
                        ->label('De parte de')
                        ->maxLength(150),

                    Forms\Components\TextInput::make('recipient_email')
                        ->label('Email del destinatario')
                        ->email()
                        ->maxLength(150)
                        ->helperText(GiftCardsSettings::isEnabled('send_email_on_issue')
                            ? 'Si lo incluyes, se enviará automáticamente al emitir.'
                            : null),

                    Forms\Components\TextInput::make('recipient_phone')
                        ->label('Teléfono del destinatario')
                        ->tel()
                        ->maxLength(30),
                ]),

            Forms\Components\Section::make('Notas')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('Notas internas')
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('Notas visibles solo en el admin, no aparecen al cliente.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->fontFamily('mono')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('initial_balance')
                    ->label('Inicial')
                    ->money('COP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('current_balance')
                    ->label('Saldo')
                    ->money('COP')
                    ->sortable()
                    ->color(fn ($record) => (float) $record->current_balance > 0 ? 'success' : 'gray')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        GiftCard::STATUS_ACTIVE => 'Activa',
                        GiftCard::STATUS_FULLY_REDEEMED => 'Redimida',
                        GiftCard::STATUS_EXPIRED => 'Expirada',
                        GiftCard::STATUS_CANCELLED => 'Cancelada',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        GiftCard::STATUS_ACTIVE => 'success',
                        GiftCard::STATUS_FULLY_REDEEMED => 'gray',
                        GiftCard::STATUS_EXPIRED => 'danger',
                        GiftCard::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('recipient_name')
                    ->label('Destinatario')
                    ->searchable()
                    ->placeholder('—')
                    ->limit(25),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expira')
                    ->date('d/m/Y')
                    ->placeholder('Sin expiración')
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emitida')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('issuedBy.name')
                    ->label('Vendida por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        GiftCard::STATUS_ACTIVE => 'Activa',
                        GiftCard::STATUS_FULLY_REDEEMED => 'Redimida',
                        GiftCard::STATUS_EXPIRED => 'Expirada',
                        GiftCard::STATUS_CANCELLED => 'Cancelada',
                    ]),
                Tables\Filters\Filter::make('expiring_soon')
                    ->label('Por expirar (30 días)')
                    ->query(fn (Builder $q) => $q->whereBetween('expires_at', [now(), now()->addDays(30)])
                        ->where('status', GiftCard::STATUS_ACTIVE)),
                Tables\Filters\Filter::make('with_balance')
                    ->label('Con saldo disponible')
                    ->query(fn (Builder $q) => $q->where('current_balance', '>', 0)
                        ->where('status', GiftCard::STATUS_ACTIVE)),
            ])
            ->actions([
                // Ver detalle (incluye historial de transacciones)
                Tables\Actions\ViewAction::make()->label('Ver'),
                Tables\Actions\EditAction::make()->label('Editar datos'),

                // Ajustar saldo manualmente (admin solo)
                Tables\Actions\Action::make('adjustBalance')
                    ->label('Ajustar saldo')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn ($record) => auth()->user()?->can('gift_cards.cancel')
                        && in_array($record->status, [GiftCard::STATUS_ACTIVE, GiftCard::STATUS_FULLY_REDEEMED], true))
                    ->modalHeading(fn ($record) => "Ajustar saldo de {$record->code}")
                    ->modalDescription('Útil para corregir errores de emisión, regalar saldo extra o descontar por reclamo. Queda registrado en el historial.')
                    ->form([
                        Forms\Components\Radio::make('direction')
                            ->label('Tipo de ajuste')
                            ->required()
                            ->default('add')
                            ->options([
                                'add' => 'Sumar saldo (+)',
                                'subtract' => 'Restar saldo (−)',
                            ]),
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto')
                            ->numeric()
                            ->minValue(1)
                            ->prefix('$')
                            ->required(),
                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo del ajuste')
                            ->rows(2)
                            ->required()
                            ->maxLength(300),
                    ])
                    ->action(function (array $data, $record) {
                        $amount = (float) $data['amount'];
                        $signedAmount = $data['direction'] === 'add' ? $amount : -$amount;
                        $newBalance = (float) $record->current_balance + $signedAmount;
                        if ($newBalance < 0) {
                            Notification::make()
                                ->title('Ajuste rechazado')
                                ->body("El saldo resultante sería negativo (\${$newBalance}).")
                                ->danger()
                                ->send();
                            return;
                        }
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $signedAmount, $newBalance, $data) {
                            $record->update([
                                'current_balance' => $newBalance,
                                'status' => $newBalance == 0
                                    ? GiftCard::STATUS_FULLY_REDEEMED
                                    : GiftCard::STATUS_ACTIVE,
                            ]);
                            $record->transactions()->create([
                                'company_id' => $record->company_id,
                                'type' => \App\Models\GiftCardTransaction::TYPE_ADJUST,
                                'amount' => $signedAmount,
                                'balance_after' => $newBalance,
                                'user_id' => auth()->id(),
                                'notes' => $data['reason'],
                            ]);
                        });
                        Notification::make()->title('Saldo ajustado')->success()->send();
                    }),

                // Anular tarjeta
                Tables\Actions\Action::make('cancel')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => auth()->user()?->can('gift_cards.cancel')
                        && $record->status === GiftCard::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Anular tarjeta {$record->code}")
                    ->modalDescription('La tarjeta no podrá usarse más. Su saldo queda en 0. Acción irreversible.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo')
                            ->rows(2)
                            ->required()
                            ->maxLength(300),
                    ])
                    ->action(function (array $data, $record) {
                        $record->cancel(auth()->id(), $data['reason']);
                        Notification::make()->title('Tarjeta anulada')->success()->send();
                    }),
            ])
            ->defaultSort('issued_at', 'desc')
            ->emptyStateHeading('Sin tarjetas regalo todavía')
            ->emptyStateDescription('Vende tu primera tarjeta regalo desde aquí o desde el POS.')
            ->emptyStateIcon('heroicon-o-gift');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGiftCards::route('/'),
            'create' => Pages\CreateGiftCard::route('/create'),
            'edit' => Pages\EditGiftCard::route('/{record}/edit'),
            'view' => Pages\ViewGiftCard::route('/{record}'),
        ];
    }
}
