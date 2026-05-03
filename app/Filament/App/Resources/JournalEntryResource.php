<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\JournalEntryResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\ThirdParty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JournalEntryResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'journal_entries.view'; }
    protected static function managePermission(): string { return 'journal_entries.create'; }

    protected static ?string $model = JournalEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Asientos contables';

    protected static ?string $modelLabel = 'Asiento';

    protected static ?string $pluralModelLabel = 'Asientos contables';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('AS')
                        ->maxLength(10)
                        ->required(),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->minValue(1)
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Auto-generado al guardar'),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha')
                        ->default(now())
                        ->required(),

                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(JournalEntry::TYPES)
                        ->default('manual')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('reference')
                        ->label('Referencia')
                        ->maxLength(100)
                        ->placeholder('Nº factura, comprobante...')
                        ->columnSpan(2),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Tercero (opcional)')
                        ->relationship('thirdParty', 'name', fn (Builder $query) => $query->where('active', true))
                        ->getOptionLabelFromRecordUsing(fn (ThirdParty $record) => "{$record->document_number} — {$record->name}")
                        ->searchable(['document_number', 'name'])
                        ->preload()
                        ->placeholder('—')
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripción / concepto')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Líneas del asiento')
                ->description('La suma de débitos debe ser igual a la suma de créditos (partida doble).')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->relationship('lines')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('account_id')
                                ->label('Cuenta')
                                ->relationship(
                                    'account',
                                    'name',
                                    fn (Builder $query) => $query
                                        ->where('accepts_movements', true)
                                        ->where('active', true)
                                        ->orderBy('code'),
                                )
                                ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                                ->searchable(['code', 'name'])
                                ->preload()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if (! $state) return;
                                    $account = Account::withoutGlobalScopes()->find($state);
                                    if ($account?->requires_third_party) {
                                        $set('_requires_third_party', true);
                                    } else {
                                        $set('_requires_third_party', false);
                                    }
                                })
                                ->columnSpan(3),

                            Forms\Components\Select::make('third_party_id')
                                ->label('Tercero')
                                ->relationship('thirdParty', 'name', fn (Builder $query) => $query->where('active', true))
                                ->getOptionLabelFromRecordUsing(fn (ThirdParty $record) => "{$record->document_number} — {$record->name}")
                                ->searchable(['document_number', 'name'])
                                ->preload()
                                ->placeholder('—')
                                ->required(fn (Forms\Get $get) => (bool) $get('_requires_third_party'))
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('description')
                                ->label('Detalle')
                                ->maxLength(250)
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('debit')
                                ->label('Débito')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->prefix('$')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if ((float) $state > 0) $set('credit', 0);
                                })
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('credit')
                                ->label('Crédito')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->prefix('$')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if ((float) $state > 0) $set('debit', 0);
                                })
                                ->columnSpan(2),

                            Forms\Components\Hidden::make('_requires_third_party'),
                        ])
                        ->columns(13)
                        ->minItems(2)
                        ->defaultItems(2)
                        ->addActionLabel('+ Añadir línea')
                        ->reorderableWithButtons()
                        ->cloneable()
                        ->live()
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Totales')
                ->columns(3)
                ->schema([
                    Forms\Components\Placeholder::make('total_debit_display')
                        ->label('Total débito')
                        ->content(function (Forms\Get $get) {
                            $sum = collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['debit'] ?? 0));

                            return '$ '.number_format($sum, 2);
                        }),

                    Forms\Components\Placeholder::make('total_credit_display')
                        ->label('Total crédito')
                        ->content(function (Forms\Get $get) {
                            $sum = collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['credit'] ?? 0));

                            return '$ '.number_format($sum, 2);
                        }),

                    Forms\Components\Placeholder::make('difference_display')
                        ->label('Diferencia')
                        ->content(function (Forms\Get $get) {
                            $debit = collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['debit'] ?? 0));
                            $credit = collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['credit'] ?? 0));
                            $diff = $debit - $credit;

                            return abs($diff) < 0.01
                                ? '✅ Cuadrado'
                                : '⚠️  $ '.number_format($diff, 2);
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (JournalEntry $record) => $record->fullNumber())
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('number', 'like', "%{$search}%")
                        ->orWhere('prefix', 'like', "%{$search}%"))
                    ->sortable(['number'])
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('date')
                    ->label('Fecha')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => JournalEntry::TYPES[$state] ?? $state)
                    ->badge(),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Ref.')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('thirdParty.name')
                    ->label('Tercero')
                    ->searchable()
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Concepto')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('total_debit')
                    ->label('Débito')
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_credit')
                    ->label('Crédito')
                    ->money('COP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => JournalEntry::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(JournalEntry::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(JournalEntry::STATUSES),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Desde'),
                        Forms\Components\DatePicker::make('to')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('post')
                    ->label('Contabilizar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (JournalEntry $record) => $record->status === 'draft'
                        && auth()->user()?->can('journal_entries.post'))
                    ->requiresConfirmation()
                    ->modalDescription(fn (JournalEntry $record) => $record->isBalanced()
                        ? "Vas a contabilizar el asiento {$record->fullNumber()}. Después de contabilizado no se podrá editar."
                        : '⚠️ El asiento NO está cuadrado (débito ≠ crédito). No se puede contabilizar.')
                    ->action(function (JournalEntry $record) {
                        if (! $record->isBalanced()) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Asiento descuadrado')
                                ->body('La suma de débitos debe ser igual a la suma de créditos.')
                                ->send();

                            return;
                        }

                        $record->update([
                            'status' => 'posted',
                            'posted_at' => now(),
                            'posted_by_user_id' => auth()->id(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Asiento contabilizado')
                            ->body("Asiento {$record->fullNumber()} contabilizado correctamente.")
                            ->send();
                    }),

                Tables\Actions\Action::make('unpost')
                    ->label('Desreversar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (JournalEntry $record) => $record->status === 'posted')
                    ->requiresConfirmation()
                    ->action(function (JournalEntry $record) {
                        $record->update([
                            'status' => 'draft',
                            'posted_at' => null,
                            'posted_by_user_id' => null,
                        ]);
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (JournalEntry $record) => $record->status === 'draft'),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (JournalEntry $record) => $record->status === 'draft'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
            'create' => Pages\CreateJournalEntry::route('/create'),
            'view' => Pages\ViewJournalEntry::route('/{record}'),
            'edit' => Pages\EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
