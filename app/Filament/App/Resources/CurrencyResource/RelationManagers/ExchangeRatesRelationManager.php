<?php

namespace App\Filament\App\Resources\CurrencyResource\RelationManagers;

use App\Models\ExchangeRate;
use App\Services\Accounting\ExchangeRateService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ExchangeRatesRelationManager extends RelationManager
{
    protected static string $relationship = 'exchangeRates';

    protected static ?string $title = 'Tasas de cambio';

    protected static ?string $modelLabel = 'Tasa';

    protected static ?string $pluralModelLabel = 'Tasas';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        // Si es moneda base, no se registran tasas (siempre 1.0).
        return ! $ownerRecord->is_base;
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\DatePicker::make('date')
                ->label('Fecha')
                ->required()
                ->default(now()),

            Forms\Components\TextInput::make('rate')
                ->label('Tasa (unidades base por 1 unidad de la moneda)')
                ->numeric()
                ->required()
                ->minValue(0.000001)
                ->helperText(fn () => sprintf(
                    'Ej. si esta moneda es USD y base es COP, ingresa cuántos COP equivalen a 1 USD (aprox. 4200).'
                )),

            Forms\Components\TextInput::make('source')
                ->label('Fuente (opcional)')
                ->maxLength(255)
                ->placeholder('BanRep, manual, exchange API, etc.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('rate')->label('Tasa')->numeric(decimalPlaces: 4)->alignEnd(),
                Tables\Columns\TextColumn::make('source')->label('Fuente')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Creado por')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Creado el')->dateTime('Y-m-d H:i')->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Registrar tasa')
                    ->mutateFormDataUsing(function (array $data) {
                        $data['created_by_user_id'] = Auth::id();
                        return $data;
                    })
                    ->after(fn () => ExchangeRateService::flushCache()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(fn () => ExchangeRateService::flushCache()),
                Tables\Actions\DeleteAction::make()
                    ->after(fn () => ExchangeRateService::flushCache()),
            ]);
    }
}
