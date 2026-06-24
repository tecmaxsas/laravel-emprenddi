<?php

namespace App\Filament\App\Pages\Parking\Reports;

use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSession;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sesiones de parqueadero por periodo. Lista cada sesion con placa, tiempo,
 * monto, estado y factura emitida (si aplica). Filtros por fechas, parqueadero
 * y estado.
 */
class ParkingSessionsReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';
    protected static ?string $navigationLabel = 'Sesiones por período';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 80;
    protected static ?string $title = 'Sesiones de parqueadero';

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return (bool) auth()->user()?->can('parking.reports');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'parking_lot_id' => null,
            'status' => null,
        ];
        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        return route('parking.reports.export.sessions', array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'parking_lot_id' => $this->filters['parking_lot_id'] ?? null,
            'status' => $this->filters['status'] ?? null,
        ]));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),
                    Forms\Components\Select::make('parking_lot_id')
                        ->label('Parqueadero')->placeholder('Todos')->live()
                        ->options(fn () => ParkingLot::query()
                            ->orderBy('name')->pluck('name', 'id')->all()),
                    Forms\Components\Select::make('status')
                        ->label('Estado')->placeholder('Todos')->live()
                        ->options(ParkingSession::STATUSES),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->baseQuery())
            ->defaultSort('entry_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Ticket')
                    ->formatStateUsing(fn ($s) => '#'.str_pad((string) $s, 6, '0', STR_PAD_LEFT))
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('plate')->label('Placa')->fontFamily('mono')->weight('semibold'),
                Tables\Columns\TextColumn::make('parkingLot.name')->label('Parqueadero')->toggleable(),
                Tables\Columns\TextColumn::make('vehicleType.name')->label('Vehículo')->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('entry_at')->label('Entrada')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('exit_at')->label('Salida')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('total_minutes')->label('Min')->alignEnd(),
                Tables\Columns\TextColumn::make('amount')->label('Monto')->money('COP')->alignEnd()->weight('semibold'),
                Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $s) => ParkingSession::STATUSES[$s] ?? $s)
                    ->color(fn (string $s) => match ($s) {
                        ParkingSession::STATUS_ACTIVE => 'info',
                        ParkingSession::STATUS_CLOSED => 'success',
                        ParkingSession::STATUS_LOST_TICKET => 'warning',
                        ParkingSession::STATUS_CANCELLED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('invoice_number')->label('Factura')
                    ->state(fn (ParkingSession $r) => $r->saleInvoice?->fullNumber() ?? '—')
                    ->fontFamily('mono')->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->summaryLabel())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')->disabled(),
            ]);
    }

    protected function baseQuery(): Builder
    {
        $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();

        return ParkingSession::query()
            ->whereDate('entry_at', '>=', $from)
            ->whereDate('entry_at', '<=', $to)
            ->when($this->filters['parking_lot_id'] ?? null, fn (Builder $q, $v) => $q->where('parking_lot_id', $v))
            ->when($this->filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->with(['parkingLot:id,name', 'vehicleType:id,name', 'saleInvoice:id,prefix,number']);
    }

    protected function summaryLabel(): string
    {
        $q = $this->baseQuery();
        $count = $q->count();
        $total = (float) $q->sum('amount');
        return sprintf('Total %d sesiones — $%s', $count, number_format($total, 0, ',', '.'));
    }
}
