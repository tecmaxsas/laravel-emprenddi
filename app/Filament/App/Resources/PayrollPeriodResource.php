<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PayrollPeriodResource\Pages;
use App\Filament\App\Resources\PayrollPeriodResource\RelationManagers\NoveltiesRelationManager;
use App\Filament\App\Resources\PayrollPeriodResource\RelationManagers\SlipsRelationManager;
use App\Filament\Concerns\ChecksPermission;
use App\Models\PayrollPeriod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayrollPeriodResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'payroll.periods.view'; }
    protected static function managePermission(): string { return 'payroll.periods.manage'; }

    protected static ?string $model = PayrollPeriod::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Liquidación de Nómina';

    protected static ?string $modelLabel = 'Período de nómina';

    protected static ?string $pluralModelLabel = 'Períodos de nómina';

    protected static ?string $navigationGroup = 'Nómina';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Período')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->placeholder('Nómina mayo 2026')
                        ->required()
                        ->maxLength(120)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('frequency')
                        ->label('Frecuencia')
                        ->options(PayrollPeriod::FREQUENCIES)
                        ->default('mensual')
                        ->native(false)
                        ->required(),

                    Forms\Components\DatePicker::make('payment_date')
                        ->label('Fecha de pago'),

                    Forms\Components\DatePicker::make('start_date')
                        ->label('Inicio del período')
                        ->default(now()->startOfMonth())
                        ->required(),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('Fin del período')
                        ->default(now()->endOfMonth())
                        ->required(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Observaciones')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('slips')
                ->withSum('slips', 'net_pay'))
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Período')->weight('semibold')->searchable(),

                Tables\Columns\TextColumn::make('frequency')
                    ->label('Frecuencia')
                    ->formatStateUsing(fn (string $state) => PayrollPeriod::FREQUENCIES[$state] ?? $state)
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Desde')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Hasta')
                    ->date('Y-m-d'),

                Tables\Columns\TextColumn::make('slips_count')
                    ->label('Empleados')
                    ->alignCenter()
                    ->badge(),

                Tables\Columns\TextColumn::make('slips_sum_net_pay')
                    ->label('Neto a pagar')
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => PayrollPeriod::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'liquidated' => 'info',
                        'posted' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(PayrollPeriod::STATUSES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (PayrollPeriod $record) => $record->isDraft()),
            ])
            ->emptyStateHeading('Sin períodos de nómina')
            ->emptyStateDescription('Crea un período y liquida la nómina de tus empleados.');
    }

    public static function getRelations(): array
    {
        return [
            NoveltiesRelationManager::class,
            SlipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollPeriods::route('/'),
            'create' => Pages\CreatePayrollPeriod::route('/create'),
            'view' => Pages\ViewPayrollPeriod::route('/{record}'),
            'edit' => Pages\EditPayrollPeriod::route('/{record}/edit'),
        ];
    }
}
