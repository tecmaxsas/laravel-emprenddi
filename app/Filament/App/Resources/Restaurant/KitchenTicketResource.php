<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\KitchenTicketResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Printer;
use App\Services\Restaurant\BrowserPrintQueue;
use App\Services\Restaurant\KitchenTicketReprinter;
use App\Support\AccountantContext;
use App\Support\ModuleGate;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Comandas ya enviadas a cocina, para consultarlas y volver a imprimirlas.
 *
 * Es solo lectura: la comanda se genera desde el POS al enviar a cocina y aqui
 * no se crea ni se edita nada, unicamente se reimprime el mismo snapshot.
 */
class KitchenTicketResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'restaurant.use';
    }

    protected static function managePermission(): string
    {
        return 'restaurant.use';
    }

    protected static ?string $model = KitchenTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Comandas';

    protected static ?string $modelLabel = 'Comanda';

    protected static ?string $pluralModelLabel = 'Comandas';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) {
            return false;
        }
        if (! AccountantContext::ready()) {
            return false;
        }

        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /** Ordenes que siguen en curso: son las comandas que interesan reimprimir. */
    public static function activeOrderStatuses(): array
    {
        return [Order::STATUS_OPEN, Order::STATUS_IN_KITCHEN, Order::STATUS_SERVED, Order::STATUS_BILLING];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('printed_at', 'desc')
            ->poll('30s')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.table', 'order.server', 'printer']))
            ->columns([
                Tables\Columns\TextColumn::make('printed_at')
                    ->label('Hora')
                    ->dateTime('d/m H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('order.number')
                    ->label('Orden')
                    ->formatStateUsing(fn ($state, KitchenTicket $record) => $record->order?->fullNumber() ?? '—')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('order.table.code')
                    ->label('Mesa')
                    ->placeholder(fn (KitchenTicket $record) => $record->order?->is_delivery ? 'Delivery' : 'Para llevar')
                    ->searchable(),

                Tables\Columns\TextColumn::make('batch_number')
                    ->label('Comanda #')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_snapshot')
                    ->label('Ítems')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) : 0),

                Tables\Columns\TextColumn::make('printer.name')
                    ->label('Destino')
                    ->placeholder('Navegador')
                    ->badge()
                    ->color(fn (KitchenTicket $record) => $record->restaurant_printer_id ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Impresión')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'printed' => 'Impresa',
                        'failed' => 'Falló',
                        'cancelled' => 'Anulada',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'printed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('order.status')
                    ->label('Orden')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? '—')
                    ->color(fn (?string $state) => match ($state) {
                        Order::STATUS_CLOSED => 'gray',
                        Order::STATUS_CANCELLED => 'danger',
                        default => 'success',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('printedBy.name')
                    ->label('Enviada por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('activas')
                    ->label('Órdenes en curso')
                    ->placeholder('Todas')
                    ->trueLabel('Solo en curso')
                    ->falseLabel('Solo cerradas o anuladas')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('order', fn (Builder $o) => $o->whereIn('status', static::activeOrderStatuses())),
                        false: fn (Builder $q) => $q->whereHas('order', fn (Builder $o) => $o->whereNotIn('status', static::activeOrderStatuses())),
                        blank: fn (Builder $q) => $q,
                    ),

                Tables\Filters\SelectFilter::make('restaurant_printer_id')
                    ->label('Destino')
                    ->options(fn () => Printer::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Impresión')
                    ->options([
                        'printed' => 'Impresa',
                        'failed' => 'Falló',
                        'cancelled' => 'Anulada',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('verItems')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (KitchenTicket $record) => 'Comanda #'.$record->batch_number.' — '.($record->order?->fullNumber() ?? ''))
                    ->modalContent(fn (KitchenTicket $record) => view('restaurant.kot-items', ['ticket' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),

                Tables\Actions\Action::make('reimprimir')
                    ->label('Reimprimir')
                    ->icon('heroicon-o-printer')
                    ->requiresConfirmation()
                    ->modalHeading(fn (KitchenTicket $record) => 'Reimprimir comanda #'.$record->batch_number)
                    ->modalDescription('Se vuelve a imprimir la misma comanda. No se generan ítems nuevos ni se altera la orden.')
                    ->modalSubmitActionLabel('Reimprimir')
                    ->action(function (KitchenTicket $record, $livewire) {
                        $result = app(KitchenTicketReprinter::class)->reprint($record);

                        if ($result['browser']) {
                            $livewire->dispatch('kot-print-browser', ticketIds: [$record->id]);
                        }

                        static::flushBrowserPrintJobs($livewire);

                        Notification::make()
                            ->title($result['ok'] ? 'Comanda reenviada' : 'No se pudo imprimir')
                            ->body($result['message'])
                            ->status($result['ok'] ? 'success' : 'danger')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('reimprimirVarias')
                    ->label('Reimprimir seleccionadas')
                    ->icon('heroicon-o-printer')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->modalHeading('Reimprimir comandas')
                    ->modalDescription('Cada comanda se reenvía a su destino. Las que no tienen impresora se abren en el navegador, una ventana por comanda.')
                    ->modalSubmitActionLabel('Reimprimir')
                    ->action(function (Collection $records, $livewire) {
                        $porNavegador = [];
                        $enviadas = 0;
                        $fallidas = 0;

                        $reprinter = app(KitchenTicketReprinter::class);

                        foreach ($records as $record) {
                            $result = $reprinter->reprint($record);

                            if ($result['browser']) {
                                $porNavegador[] = $record->id;

                                continue;
                            }

                            $result['ok'] ? $enviadas++ : $fallidas++;
                        }

                        if ($porNavegador !== []) {
                            $livewire->dispatch('kot-print-browser', ticketIds: $porNavegador);
                        }

                        static::flushBrowserPrintJobs($livewire);

                        $partes = [];
                        if ($enviadas) {
                            $partes[] = "{$enviadas} a impresora";
                        }
                        if ($porNavegador !== []) {
                            $partes[] = count($porNavegador).' por navegador';
                        }
                        if ($fallidas) {
                            $partes[] = "{$fallidas} con error";
                        }

                        Notification::make()
                            ->title('Reimpresión enviada')
                            ->body(implode(' · ', $partes) ?: 'No había comandas que reimprimir.')
                            ->status($fallidas > 0 ? 'warning' : 'success')
                            ->send();
                    }),
            ]);
    }

    /**
     * Vacia la cola de QZ Tray al front. Las impresoras tipo 'browser' no
     * imprimen desde el servidor: encolan y la pagina despacha el trabajo.
     */
    protected static function flushBrowserPrintJobs($livewire): void
    {
        $jobs = app(BrowserPrintQueue::class)->flush();

        if (! empty($jobs)) {
            $livewire->dispatch('qz-print-jobs', jobs: $jobs);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKitchenTickets::route('/'),
        ];
    }
}
