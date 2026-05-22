<?php

namespace App\Filament\App\Resources\PartnerResource\RelationManagers;

use App\Models\PartnerMovement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Movimientos de capital';

    protected static ?string $modelLabel = 'Movimiento';

    protected static ?string $pluralModelLabel = 'Movimientos';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\DatePicker::make('date')
                ->label('Fecha')
                ->default(now())
                ->required(),

            Forms\Components\Select::make('movement_type')
                ->label('Tipo de movimiento')
                ->options(PartnerMovement::TYPES)
                ->default(PartnerMovement::TYPE_ADDITIONAL)
                ->native(false)
                ->required()
                ->helperText('Cesión entregada y retiro reducen el capital del socio automáticamente.'),

            Forms\Components\TextInput::make('quotas')
                ->label('Cuotas / Acciones')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required()
                ->helperText('Cantidad de cuotas o acciones del movimiento (en positivo).'),

            Forms\Components\TextInput::make('unit_value')
                ->label('Valor nominal por cuota (opcional)')
                ->numeric()
                ->minValue(0)
                ->prefix('$'),

            Forms\Components\TextInput::make('amount')
                ->label('Valor total del movimiento')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required()
                ->prefix('$')
                ->helperText('Monto de capital del movimiento (en positivo).'),

            Forms\Components\TextInput::make('reference')
                ->label('Referencia (opcional)')
                ->maxLength(80)
                ->placeholder('Escritura, acta de asamblea, etc.'),

            Forms\Components\Textarea::make('notes')
                ->label('Observaciones')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('movement_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => PartnerMovement::TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn (PartnerMovement $record) => $record->isOutflow() ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('quotas')
                    ->label('Cuotas')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Valor')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('reference')->label('Referencia')->placeholder('—')->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Registrar movimiento'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
