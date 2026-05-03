<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\AccountResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'accounts.view'; }
    protected static function managePermission(): string { return 'accounts.manage'; }

    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'Plan de Cuentas';

    protected static ?string $modelLabel = 'Cuenta';

    protected static ?string $pluralModelLabel = 'Plan de Cuentas';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código')
                ->required()
                ->maxLength(20)
                ->numeric()
                ->live(onBlur: true)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule
                    ->where('company_id', auth()->user()->company_id))
                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                    if (! $state) {
                        return;
                    }
                    $set('level', Account::levelFromCode((string) $state));
                    if (! $get('type')) {
                        $set('type', Account::typeFromCode((string) $state));
                    }
                    $type = $get('type') ?? Account::typeFromCode((string) $state);
                    $set('nature', Account::TYPE_NATURE[$type] ?? 'debit');
                }),

            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(200),

            Forms\Components\Select::make('type')
                ->label('Tipo')
                ->options(Account::TYPES)
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $set('nature', Account::TYPE_NATURE[$state] ?? 'debit');
                }),

            Forms\Components\Select::make('nature')
                ->label('Naturaleza')
                ->options(['debit' => 'Débito', 'credit' => 'Crédito'])
                ->required(),

            Forms\Components\Select::make('parent_id')
                ->label('Cuenta padre')
                ->relationship('parent', 'name', fn (Builder $query) => $query->orderBy('code'))
                ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                ->searchable(['code', 'name'])
                ->preload()
                ->placeholder('Sin padre (cuenta raíz)'),

            Forms\Components\TextInput::make('level')
                ->label('Nivel')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(5)
                ->disabled()
                ->dehydrated()
                ->helperText('Calculado del código'),

            Forms\Components\Section::make('Comportamiento')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('accepts_movements')
                        ->label('Acepta movimientos')
                        ->helperText('Solo cuentas hoja (sin hijas) deben recibir movimientos.'),

                    Forms\Components\Toggle::make('requires_third_party')
                        ->label('Exige tercero')
                        ->helperText('Cliente/proveedor obligatorio en cada movimiento.'),

                    Forms\Components\Toggle::make('requires_cost_center')
                        ->label('Exige centro de costo'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true),
                ]),

            Forms\Components\Textarea::make('description')
                ->label('Descripción / Notas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->weight(fn (Account $record) => $record->level <= 2 ? 'bold' : 'normal'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight(fn (Account $record) => $record->level <= 2 ? 'bold' : 'normal'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => Account::TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'activo' => 'success',
                        'pasivo' => 'warning',
                        'patrimonio' => 'info',
                        'ingreso' => 'success',
                        'gasto', 'costo_venta', 'costo_produccion' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('nature')
                    ->label('Nat.')
                    ->formatStateUsing(fn (string $state) => $state === 'debit' ? 'DB' : 'CR')
                    ->badge()
                    ->color(fn (string $state) => $state === 'debit' ? 'success' : 'danger')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('level')
                    ->label('Nivel')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('accepts_movements')
                    ->label('Mov.')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('requires_third_party')
                    ->label('Tercero')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_system')
                    ->label('Sistema')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Clase')
                    ->options(Account::TYPES),

                Tables\Filters\SelectFilter::make('level')
                    ->label('Nivel')
                    ->options([
                        1 => '1 — Clase',
                        2 => '2 — Grupo',
                        3 => '3 — Cuenta',
                        4 => '4 — Subcuenta',
                        5 => '5 — Auxiliar',
                    ]),

                Tables\Filters\TernaryFilter::make('accepts_movements')->label('Acepta movimientos'),
                Tables\Filters\TernaryFilter::make('active')->label('Activa'),
                Tables\Filters\TernaryFilter::make('is_system')->label('Cuenta del PUC oficial'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Account $record) => ! $record->is_system),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['active' => true])),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn ($records) => $records->each->update(['active' => false])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}
