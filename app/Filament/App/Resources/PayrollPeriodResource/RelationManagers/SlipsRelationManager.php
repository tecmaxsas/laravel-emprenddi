<?php

namespace App\Filament\App\Resources\PayrollPeriodResource\RelationManagers;

use App\Models\PayrollSlip;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SlipsRelationManager extends RelationManager
{
    protected static string $relationship = 'slips';

    protected static ?string $title = 'Desprendibles';

    protected static ?string $modelLabel = 'Desprendible';

    protected static ?string $pluralModelLabel = 'Desprendibles';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee'))
            ->columns([
                Tables\Columns\TextColumn::make('employee')
                    ->label('Empleado')
                    ->state(fn (PayrollSlip $record) => $record->employee?->fullName())
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereHas('employee', fn ($q) => $q
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%"))),

                Tables\Columns\TextColumn::make('worked_days')->label('Días')->alignCenter(),

                Tables\Columns\TextColumn::make('total_earnings')->label('Devengado')->money('COP')->alignEnd(),

                Tables\Columns\TextColumn::make('total_deductions')->label('Deducciones')->money('COP')->alignEnd(),

                Tables\Columns\TextColumn::make('net_pay')
                    ->label('Neto a pagar')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('employer_cost')
                    ->label('Costo empleador')
                    ->state(fn (PayrollSlip $record) => $record->employerCost())
                    ->money('COP')
                    ->alignEnd()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Desprendible')
                    ->modalHeading(fn (PayrollSlip $record) => 'Desprendible — '.$record->employee?->fullName())
                    ->infolist(fn (Infolist $infolist) => $infolist->schema([
                        Infolists\Components\Section::make('Devengados y deducciones')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('lines')
                                    ->label('')
                                    ->schema([
                                        Infolists\Components\TextEntry::make('concept_name')
                                            ->label('Concepto')
                                            ->columnSpan(2),
                                        Infolists\Components\TextEntry::make('type')
                                            ->label('Tipo')
                                            ->formatStateUsing(fn (string $state) => $state === 'earning' ? 'Devengado' : 'Deducción')
                                            ->badge()
                                            ->color(fn (string $state) => $state === 'earning' ? 'success' : 'danger'),
                                        Infolists\Components\TextEntry::make('amount')
                                            ->label('Valor')
                                            ->money('COP'),
                                    ])
                                    ->columns(4),
                            ]),

                        Infolists\Components\Section::make('Resumen')
                            ->columns(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_earnings')->label('Total devengado')->money('COP'),
                                Infolists\Components\TextEntry::make('total_deductions')->label('Total deducciones')->money('COP'),
                                Infolists\Components\TextEntry::make('net_pay')->label('Neto a pagar')->money('COP')->weight('bold'),
                            ]),

                        Infolists\Components\Section::make('Aportes del empleador')
                            ->columns(3)
                            ->collapsed()
                            ->schema([
                                Infolists\Components\TextEntry::make('employer_health')->label('Salud')->money('COP'),
                                Infolists\Components\TextEntry::make('employer_pension')->label('Pensión')->money('COP'),
                                Infolists\Components\TextEntry::make('employer_arl')->label('ARL')->money('COP'),
                                Infolists\Components\TextEntry::make('employer_caja')->label('Caja de compensación')->money('COP'),
                                Infolists\Components\TextEntry::make('employer_sena')->label('SENA')->money('COP'),
                                Infolists\Components\TextEntry::make('employer_icbf')->label('ICBF')->money('COP'),
                            ]),

                        Infolists\Components\Section::make('Provisiones')
                            ->columns(4)
                            ->collapsed()
                            ->schema([
                                Infolists\Components\TextEntry::make('prov_cesantias')->label('Cesantías')->money('COP'),
                                Infolists\Components\TextEntry::make('prov_interest')->label('Intereses cesantías')->money('COP'),
                                Infolists\Components\TextEntry::make('prov_prima')->label('Prima')->money('COP'),
                                Infolists\Components\TextEntry::make('prov_vacaciones')->label('Vacaciones')->money('COP'),
                            ]),
                    ])),
            ]);
    }
}
