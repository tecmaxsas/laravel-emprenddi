<?php

namespace App\Filament\App\Resources\PayrollPeriodResource\RelationManagers;

use App\Models\PayrollSlip;
use App\Services\Dian\PayrollDianSender;
use App\Support\ModuleGate;
use Filament\Notifications\Notification;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
                Tables\Columns\TextColumn::make('dian_status')
                    ->label('DIAN')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => PayrollSlip::DIAN_STATUSES[$state] ?? '—')
                    ->color(fn (?string $state) => match ($state) {
                        PayrollSlip::DIAN_ACCEPTED => 'success',
                        PayrollSlip::DIAN_REJECTED => 'danger',
                        PayrollSlip::DIAN_SENT => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (PayrollSlip $record) => $record->cune
                        ? 'CUNE '.substr($record->cune, 0, 12).'…'
                        : $record->dian_error_message),
            ])
            ->actions([
                Tables\Actions\Action::make('sendDian')
                    ->label('Enviar a DIAN')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (PayrollSlip $record) => ModuleGate::active('payroll')
                        && $record->dian_status !== PayrollSlip::DIAN_ACCEPTED)
                    ->requiresConfirmation()
                    ->modalHeading('Enviar la nómina a la DIAN')
                    ->modalDescription(fn (PayrollSlip $record) => 'Se reportará el desprendible de '
                        .$record->employee?->fullName().'. Si la DIAN lo acepta ya no se puede volver a enviar: '
                        .'para corregirlo hay que emitir una nota de ajuste.')
                    ->modalSubmitActionLabel('Enviar')
                    ->action(function (PayrollSlip $record) {
                        try {
                            $resultado = app(PayrollDianSender::class)->send($record);

                            $resultado['ok']
                                ? Notification::make()->success()
                                    ->title('Nómina aceptada por la DIAN')
                                    ->body('CUNE '.$resultado['cune'])
                                    ->send()
                                : Notification::make()->danger()
                                    ->title('La DIAN no aceptó el documento')
                                    ->body($resultado['message'])
                                    ->persistent()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()
                                ->title('No se pudo enviar')
                                ->body($e->getMessage())
                                ->persistent()->send();
                        }
                    }),

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
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('sendDianBulk')
                    ->label('Enviar a DIAN')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn () => ModuleGate::active('payroll'))
                    ->requiresConfirmation()
                    ->modalHeading('Enviar varias nóminas a la DIAN')
                    ->modalDescription('Se envía una por una. Las que ya estén aceptadas se omiten. '
                        .'Si alguna falla, las demás siguen: al final se dice cuáles y por qué.')
                    ->modalSubmitActionLabel('Enviar')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $enviadas = 0;
                        $fallos = [];

                        foreach ($records as $colilla) {
                            // Una colilla que falla no puede frenar a las demas:
                            // en una nomina de 50 empleados eso obligaria a
                            // relanzar todo por un solo caso.
                            if ($colilla->dian_status === PayrollSlip::DIAN_ACCEPTED) {
                                continue;
                            }

                            try {
                                $resultado = app(PayrollDianSender::class)->send($colilla);

                                $resultado['ok']
                                    ? $enviadas++
                                    : $fallos[] = $colilla->employee?->fullName().': '.$resultado['message'];
                            } catch (\Throwable $e) {
                                $fallos[] = $colilla->employee?->fullName().': '.$e->getMessage();
                            }
                        }

                        if ($fallos === []) {
                            Notification::make()->success()
                                ->title($enviadas.' nómina(s) aceptadas por la DIAN')
                                ->send();

                            return;
                        }

                        Notification::make()->warning()
                            ->title($enviadas.' aceptadas · '.count($fallos).' con problema')
                            ->body(implode(' — ', array_slice($fallos, 0, 4))
                                .(count($fallos) > 4 ? ' …y '.(count($fallos) - 4).' más.' : ''))
                            ->persistent()
                            ->send();
                    }),
            ]);
    }
}
