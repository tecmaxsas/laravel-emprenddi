<?php

namespace App\Filament\App\Resources\WarrantyResource\Pages;

use App\Filament\App\Resources\WarrantyResource;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Warranties\WarrantyEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Pantalla central de un ticket. Muestra info del equipo + cliente,
 * timeline de eventos y acciones rápidas:
 *  - transición de estado (solo botones permitidos por WarrantyEngine::TRANSITIONS)
 *  - asignar técnico
 *  - añadir comentario
 *  - abrir el comprobante imprimible
 *
 * Las acciones disparan WarrantyEngine para que el evento quede en la bitácora.
 */
class ViewWarranty extends ViewRecord
{
    protected static string $resource = WarrantyResource::class;

    public function getTitle(): string
    {
        return 'Garantía '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        $record = $this->record;
        $can = (bool) auth()->user()?->can('warranties.manage');

        $allowedTransitions = WarrantyEngine::TRANSITIONS[$record->status] ?? [];

        return [
            // Imprimir comprobante de recepción
            Actions\Action::make('print')
                ->label('Imprimir comprobante')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('warranties.print', $record), shouldOpenInNewTab: true),

            // Botones de transición — uno por cada estado permitido
            Actions\ActionGroup::make(array_map(
                fn (string $toStatus) => Actions\Action::make('to_'.$toStatus)
                    ->label('Pasar a "'.(Warranty::STATUSES[$toStatus] ?? $toStatus).'"')
                    ->icon(self::iconForStatus($toStatus))
                    ->color(self::colorForStatus($toStatus))
                    ->requiresConfirmation()
                    ->modalHeading('Cambiar estado a "'.(Warranty::STATUSES[$toStatus] ?? $toStatus).'"')
                    ->form([
                        Forms\Components\Textarea::make('comment')
                            ->label('Nota de la transición (opcional)')
                            ->rows(3)
                            ->placeholder('Ej: cambio de batería bajo garantía, equipo funcional.'),
                    ])
                    ->action(function (array $data) use ($toStatus) {
                        try {
                            app(WarrantyEngine::class)->transitionTo(
                                $this->record,
                                $toStatus,
                                $data['comment'] ?? null,
                            );
                            Notification::make()->success()
                                ->title('Estado actualizado')
                                ->body('Nuevo estado: '.(Warranty::STATUSES[$toStatus] ?? $toStatus))
                                ->send();
                            $this->refreshFormData(['status', 'resolved_at', 'delivered_at']);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()
                                ->title('No se pudo cambiar el estado')
                                ->body($e->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
                $allowedTransitions,
            ))
                ->label('Cambiar estado')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->button()
                ->visible(fn () => $can && ! empty($allowedTransitions)),

            // Asignar técnico
            Actions\Action::make('assign')
                ->label('Asignar técnico')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->visible(fn () => $can && ! $record->isTerminal())
                ->form([
                    Forms\Components\Select::make('user_id')
                        ->label('Técnico')
                        ->required()
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')),
                    Forms\Components\Textarea::make('comment')
                        ->label('Nota (opcional)')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    app(WarrantyEngine::class)->assign(
                        $this->record,
                        (int) $data['user_id'],
                        $data['comment'] ?? null,
                    );
                    Notification::make()->success()->title('Técnico asignado')->send();
                    $this->refreshFormData(['assigned_user_id']);
                }),

            // Comentario libre
            Actions\Action::make('comment')
                ->label('Agregar comentario')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->form([
                    Forms\Components\Textarea::make('text')
                        ->label('Comentario')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    app(WarrantyEngine::class)->comment($this->record, $data['text']);
                    Notification::make()->success()->title('Comentario agregado')->send();
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Equipo y cliente')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Ticket')
                        ->state(fn (Warranty $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('rma_number')
                        ->label('RMA')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (string $s) => Warranty::STATUSES[$s] ?? $s)
                        ->color(fn (string $s) => match ($s) {
                            Warranty::STATUS_RECEIVED => 'gray',
                            Warranty::STATUS_IN_REVIEW => 'info',
                            Warranty::STATUS_IN_REPAIR => 'warning',
                            Warranty::STATUS_RESOLVED, Warranty::STATUS_REPLACED => 'success',
                            Warranty::STATUS_REJECTED => 'danger',
                            Warranty::STATUS_DELIVERED => 'primary',
                            default => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('product.name')
                        ->label('Producto')->columnSpan(2),
                    Infolists\Components\TextEntry::make('serial.serial_number')
                        ->label('Serial')->fontFamily('mono')->placeholder('—'),

                    Infolists\Components\TextEntry::make('customer.name')
                        ->label('Cliente')->columnSpan(2),
                    Infolists\Components\TextEntry::make('customer.document_number')
                        ->label('Documento'),
                    Infolists\Components\TextEntry::make('customer.phone')
                        ->label('Teléfono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('customer.email')
                        ->label('Email')->placeholder('—')->columnSpan(2),
                ]),

            Infolists\Components\Section::make('Fechas y plazo')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('claim_date')
                        ->label('Recibido')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('expiration_date')
                        ->label('Vence garantía')
                        ->date('Y-m-d')
                        ->placeholder('—')
                        ->color(fn (Warranty $r) => $r->isExpired() ? 'danger' : null)
                        ->badge(fn (Warranty $r) => $r->isExpired()),
                    Infolists\Components\TextEntry::make('resolved_at')
                        ->label('Resuelta')->dateTime('Y-m-d H:i')->placeholder('—'),
                    Infolists\Components\TextEntry::make('delivered_at')
                        ->label('Entregada')->dateTime('Y-m-d H:i')->placeholder('—'),

                    Infolists\Components\TextEntry::make('location.name')
                        ->label('Sede')->placeholder('—'),
                    Infolists\Components\TextEntry::make('assignedUser.name')
                        ->label('Técnico asignado')->placeholder('— Sin asignar —'),
                    Infolists\Components\TextEntry::make('receivedByUser.name')
                        ->label('Recibido por')->placeholder('—'),
                    Infolists\Components\TextEntry::make('saleInvoice.full_number')
                        ->label('Factura de venta')
                        ->state(fn (Warranty $r) => $r->saleInvoice?->fullNumber())
                        ->placeholder('—')
                        ->url(fn (Warranty $r) => $r->saleInvoice
                            ? route('filament.app.resources.sale-invoices.view', $r->saleInvoice)
                            : null),
                ]),

            Infolists\Components\Section::make('Problema reportado')
                ->schema([
                    Infolists\Components\TextEntry::make('reason')->label(''),
                ]),

            Infolists\Components\Section::make('Notas de resolución')
                ->visible(fn (Warranty $r) => ! empty($r->resolution_notes))
                ->schema([
                    Infolists\Components\TextEntry::make('resolution_notes')->label(''),
                ]),

            Infolists\Components\Section::make('Historial')
                ->schema([
                    Infolists\Components\ViewEntry::make('events_timeline')
                        ->view('filament.app.resources.warranties.timeline'),
                ]),
        ]);
    }

    protected static function iconForStatus(string $status): string
    {
        return match ($status) {
            Warranty::STATUS_IN_REVIEW => 'heroicon-o-magnifying-glass',
            Warranty::STATUS_IN_REPAIR => 'heroicon-o-wrench-screwdriver',
            Warranty::STATUS_RESOLVED => 'heroicon-o-check-circle',
            Warranty::STATUS_REPLACED => 'heroicon-o-arrow-path-rounded-square',
            Warranty::STATUS_REJECTED => 'heroicon-o-x-circle',
            Warranty::STATUS_DELIVERED => 'heroicon-o-truck',
            default => 'heroicon-o-arrow-right',
        };
    }

    protected static function colorForStatus(string $status): string
    {
        return match ($status) {
            Warranty::STATUS_RESOLVED, Warranty::STATUS_REPLACED => 'success',
            Warranty::STATUS_REJECTED => 'danger',
            Warranty::STATUS_DELIVERED => 'primary',
            Warranty::STATUS_IN_REPAIR => 'warning',
            default => 'gray',
        };
    }
}
