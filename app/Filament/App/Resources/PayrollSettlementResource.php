<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PayrollSettlementResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Employee;
use App\Models\PayrollSettlement;
use App\Services\Payroll\PayrollSettlementCalculator;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayrollSettlementResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'payroll.settlements.manage'; }
    protected static function managePermission(): string { return 'payroll.settlements.manage'; }

    protected static ?string $model = PayrollSettlement::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Prestaciones Sociales';

    protected static ?string $modelLabel = 'Liquidación de prestaciones';

    protected static ?string $pluralModelLabel = 'Liquidaciones de prestaciones';

    protected static ?string $navigationGroup = 'Nómina';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Liquidación')
                ->description('Calcula cesantías, intereses, prima, vacaciones — o la liquidación definitiva al retiro.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('employee_id')
                        ->label('Empleado')
                        ->options(fn () => Employee::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])
                            ->all())
                        ->searchable()
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('type')
                        ->label('Tipo de liquidación')
                        ->options(PayrollSettlement::TYPES)
                        ->required()
                        ->native(false)
                        ->live()
                        ->columnSpanFull(),

                    Forms\Components\DatePicker::make('period_start')
                        ->label('Desde')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::syncWorkedDays($set, $get)),

                    Forms\Components\DatePicker::make('period_end')
                        ->label('Hasta')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::syncWorkedDays($set, $get)),

                    Forms\Components\TextInput::make('worked_days')
                        ->label('Días liquidados')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText('Calculado con la convención de 360 días (mes de 30). Ajustable.'),

                    Forms\Components\TextInput::make('amount_indemnizacion')
                        ->label('Indemnización por despido')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->default(0)
                        ->visible(fn (Forms\Get $get) => $get('type') === PayrollSettlement::TYPE_DEFINITIVA)
                        ->helperText('Solo si hubo despido sin justa causa. Indefinido: 30 días de salario por el primer año + 20 por cada año adicional (salario inferior a 10 SMMLV). Dejar en 0 si fue renuncia o despido con justa causa.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Observaciones')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Recalcula los días (convención 360) cuando cambian las fechas. */
    protected static function syncWorkedDays(Forms\Set $set, Forms\Get $get): void
    {
        $start = $get('period_start');
        $end = $get('period_end');
        if ($start && $end) {
            $days = app(PayrollSettlementCalculator::class)->days360(
                Carbon::parse($start),
                Carbon::parse($end),
            );
            $set('worked_days', $days);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('employee')
                    ->label('Empleado')
                    ->state(fn (PayrollSettlement $record) => $record->employee?->fullName())
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereHas('employee', fn ($q) => $q
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%"))),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => PayrollSettlement::TYPES[$state] ?? $state)
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('period')
                    ->label('Período')
                    ->state(fn (PayrollSettlement $record) => $record->period_start?->format('Y-m-d')
                        .' → '.$record->period_end?->format('Y-m-d')),

                Tables\Columns\TextColumn::make('worked_days')->label('Días')->alignCenter()->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Valor')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => PayrollSettlement::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => $state === PayrollSettlement::STATUS_PAID ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(PayrollSettlement::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(PayrollSettlement::STATUSES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->emptyStateHeading('Sin liquidaciones')
            ->emptyStateDescription('Liquidá cesantías, prima, vacaciones o la liquidación definitiva de un empleado.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrollSettlements::route('/'),
            'create' => Pages\CreatePayrollSettlement::route('/create'),
            'view' => Pages\ViewPayrollSettlement::route('/{record}'),
        ];
    }
}
