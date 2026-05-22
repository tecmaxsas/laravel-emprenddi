<?php

namespace App\Filament\App\Resources\PayrollPeriodResource\Pages;

use App\Filament\App\Resources\PayrollPeriodResource;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollEngine;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPayrollPeriod extends ViewRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    public function getTitle(): string
    {
        return $this->record instanceof PayrollPeriod ? $this->record->name : 'Período de nómina';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (PayrollPeriod $record) => $record->isDraft()),

            Actions\Action::make('liquidate')
                ->label(fn (PayrollPeriod $record) => $record->isLiquidated() ? 'Re-liquidar nómina' : 'Liquidar nómina')
                ->icon('heroicon-o-calculator')
                ->color('success')
                ->visible(fn (PayrollPeriod $record) => ! $record->isPosted()
                    && auth()->user()?->can('payroll.periods.manage'))
                ->requiresConfirmation()
                ->modalHeading('Liquidar nómina del período')
                ->modalDescription('Se generará un desprendible por cada empleado activo con contrato vigente. Si el período ya estaba liquidado, se reemplazan los desprendibles.')
                ->modalSubmitActionLabel('Liquidar')
                ->action(function (PayrollPeriod $record) {
                    try {
                        $period = app(PayrollEngine::class)->liquidate($record);
                        Notification::make()
                            ->success()
                            ->title('Nómina liquidada')
                            ->body($period->slips()->count().' empleado(s) liquidado(s).')
                            ->send();
                        $this->refreshFormData(['status', 'liquidated_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('No se pudo liquidar')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Período')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('name')->label('Nombre')->weight('bold')->columnSpan(2),
                    Infolists\Components\TextEntry::make('frequency')
                        ->label('Frecuencia')
                        ->formatStateUsing(fn (string $state) => PayrollPeriod::FREQUENCIES[$state] ?? $state)
                        ->badge(),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => PayrollPeriod::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'liquidated' => 'info', 'posted' => 'success', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('start_date')->label('Desde')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('end_date')->label('Hasta')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('payment_date')->label('Fecha de pago')->date('Y-m-d')->placeholder('—'),
                    Infolists\Components\TextEntry::make('liquidated_at')->label('Liquidada')->dateTime('Y-m-d H:i')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('total_employees')
                        ->label('Empleados')
                        ->state(fn (PayrollPeriod $record) => $record->slips()->count()),
                    Infolists\Components\TextEntry::make('total_earnings')
                        ->label('Total devengado')
                        ->state(fn (PayrollPeriod $record) => $record->slips()->sum('total_earnings'))
                        ->money('COP'),
                    Infolists\Components\TextEntry::make('total_deductions')
                        ->label('Total deducciones')
                        ->state(fn (PayrollPeriod $record) => $record->slips()->sum('total_deductions'))
                        ->money('COP'),
                    Infolists\Components\TextEntry::make('total_net')
                        ->label('Neto a pagar')
                        ->state(fn (PayrollPeriod $record) => $record->slips()->sum('net_pay'))
                        ->money('COP')
                        ->weight('bold'),
                ]),
        ]);
    }
}
