<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CurrencyResource\Pages;
use App\Filament\App\Resources\CurrencyResource\RelationManagers;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Currency;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CurrencyResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'accounts.view'; }
    protected static function managePermission(): string { return 'accounts.manage'; }

    protected static ?string $model = Currency::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Monedas';

    protected static ?string $modelLabel = 'Moneda';

    protected static ?string $pluralModelLabel = 'Monedas';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 110;

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código ISO 4217')
                ->required()
                ->maxLength(3)
                ->placeholder('COP / USD / EUR')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', auth()->user()->company_id))
                ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                ->dehydrateStateUsing(fn ($state) => strtoupper((string) $state)),

            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(50)
                ->placeholder('Peso colombiano / Dólar / Euro'),

            Forms\Components\TextInput::make('symbol')
                ->label('Símbolo')
                ->required()
                ->maxLength(5)
                ->default('$'),

            Forms\Components\TextInput::make('decimals')
                ->label('Decimales')
                ->numeric()
                ->minValue(0)
                ->maxValue(6)
                ->default(2),

            Forms\Components\Toggle::make('is_base')
                ->label('Es moneda base de la empresa')
                ->helperText('Solo puede haber una moneda base. Si la marcas aquí, se desmarca cualquier otra.')
                ->inline(false),

            Forms\Components\Toggle::make('active')
                ->label('Activa')
                ->default(true)
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('is_base', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nombre')->wrap(),
                Tables\Columns\TextColumn::make('symbol')->label('Símbolo')->alignCenter(),
                Tables\Columns\TextColumn::make('decimals')->label('Decimales')->alignCenter(),

                Tables\Columns\IconColumn::make('is_base')->label('Base')->boolean(),
                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),

                Tables\Columns\TextColumn::make('latestRate')
                    ->label('Última tasa')
                    ->state(function (Currency $r) {
                        if ($r->is_base) return '—';
                        $rate = $r->latestRate();
                        if (! $rate) return 'Sin registrar';
                        return number_format((float) $rate->rate, 4).' ('.$rate->date->format('Y-m-d').')';
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ExchangeRatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCurrencies::route('/'),
            'create' => Pages\CreateCurrency::route('/create'),
            'edit' => Pages\EditCurrency::route('/{record}/edit'),
        ];
    }
}
