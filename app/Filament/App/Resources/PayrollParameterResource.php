<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PayrollParameterResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\PayrollParameter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PayrollParameterResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'payroll.parameters.manage'; }
    protected static function managePermission(): string { return 'payroll.parameters.manage'; }

    protected static ?string $model = PayrollParameter::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Parámetros de Nómina';

    protected static ?string $modelLabel = 'Parámetro de nómina';

    protected static ?string $pluralModelLabel = 'Parámetros de nómina';

    protected static ?string $navigationGroup = 'Nómina';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Valores legales del año')
                ->description('El Gobierno fija estos valores cada año. La liquidación los usa según el año del período.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('year')
                        ->label('Año')
                        ->numeric()
                        ->minValue(2020)
                        ->maxValue(2100)
                        ->default(now()->year)
                        ->required()
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule) => $rule
                                ->where('company_id', auth()->user()?->company_id),
                        )
                        ->validationMessages(['unique' => 'Ya hay parámetros para ese año.']),

                    Forms\Components\TextInput::make('smmlv')
                        ->label('Salario mínimo (SMMLV)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->required(),

                    Forms\Components\TextInput::make('transport_allowance')
                        ->label('Auxilio de transporte')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->required(),

                    Forms\Components\TextInput::make('uvt')
                        ->label('UVT')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->helperText('Unidad de Valor Tributario — se usará para la retención en la fuente.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('year')->label('Año')->weight('bold')->sortable(),
                Tables\Columns\TextColumn::make('smmlv')->label('SMMLV')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('transport_allowance')->label('Auxilio transporte')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('uvt')->label('UVT')->money('COP')->alignEnd()->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin parámetros')
            ->emptyStateDescription('Configurá el SMMLV y el auxilio de transporte de cada año para poder liquidar nómina.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollParameters::route('/'),
            'create' => Pages\CreatePayrollParameter::route('/create'),
            'edit' => Pages\EditPayrollParameter::route('/{record}/edit'),
        ];
    }
}
