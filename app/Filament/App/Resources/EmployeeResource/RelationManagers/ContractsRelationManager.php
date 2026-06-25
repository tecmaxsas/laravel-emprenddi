<?php

namespace App\Filament\App\Resources\EmployeeResource\RelationManagers;

use App\Models\EmploymentContract;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Contratos';

    protected static ?string $modelLabel = 'Contrato';

    protected static ?string $pluralModelLabel = 'Contratos';

    protected static ?string $icon = 'heroicon-o-document-text';

    public function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Select::make('contract_type')
                ->label('Tipo de contrato')
                ->options(EmploymentContract::CONTRACT_TYPES)
                ->default('indefinido')
                ->native(false)
                ->required(),

            Forms\Components\Select::make('salary_type')
                ->label('Tipo de salario')
                ->options(EmploymentContract::SALARY_TYPES)
                ->default('ordinario')
                ->native(false)
                ->required(),

            Forms\Components\TextInput::make('position')
                ->label('Cargo')
                ->required()
                ->maxLength(100),

            Forms\Components\TextInput::make('salary')
                ->label('Salario')
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->required(),

            Forms\Components\Select::make('payment_frequency')
                ->label('Frecuencia de pago')
                ->options(EmploymentContract::PAYMENT_FREQUENCIES)
                ->default('mensual')
                ->native(false)
                ->required(),

            Forms\Components\Toggle::make('transport_allowance_applies')
                ->label('Aplica auxilio de transporte')
                ->default(true)
                ->inline(false)
                ->helperText('Solo si el salario es ≤ 2 SMMLV.'),

            Forms\Components\DatePicker::make('start_date')
                ->label('Fecha de inicio')
                ->default(now())
                ->required(),

            Forms\Components\DatePicker::make('end_date')
                ->label('Fecha de fin')
                ->helperText('Vacío para término indefinido.'),

            Forms\Components\Select::make('risk_class')
                ->label('Clase de riesgo (ARL)')
                ->options(EmploymentContract::RISK_CLASSES)
                ->native(false),

            Forms\Components\Select::make('work_location_id')
                ->label('Sede de trabajo')
                ->options(fn () => Location::query()
                    ->where('company_id', $this->getOwnerRecord()->company_id)
                    ->where('active', true)
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Location $l) => [$l->id => $l->fullName()])
                    ->all())
                ->native(false)
                ->columnSpan(2),

            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options(EmploymentContract::STATUSES)
                ->default(EmploymentContract::STATUS_ACTIVE)
                ->native(false)
                ->live()
                ->required(),

            Forms\Components\DatePicker::make('termination_date')
                ->label('Fecha de terminación')
                ->visible(fn (Forms\Get $get) => $get('status') === EmploymentContract::STATUS_TERMINATED)
                ->columnSpan(1),

            Forms\Components\TextInput::make('termination_reason')
                ->label('Motivo de terminación')
                ->maxLength(200)
                ->visible(fn (Forms\Get $get) => $get('status') === EmploymentContract::STATUS_TERMINATED)
                ->columnSpan(2),

            Forms\Components\Textarea::make('notes')
                ->label('Observaciones')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('position')
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('position')->label('Cargo')->weight('semibold'),

                Tables\Columns\TextColumn::make('contract_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => EmploymentContract::CONTRACT_TYPES[$state] ?? $state)
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('salary')->label('Salario')->money('COP')->alignEnd()->weight('semibold'),

                Tables\Columns\TextColumn::make('start_date')->label('Inicio')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('end_date')->label('Fin')->date('Y-m-d')->placeholder('Indefinido'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => EmploymentContract::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => $state === EmploymentContract::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Nuevo contrato'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
