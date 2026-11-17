<?php

namespace App\Filament\App\Pages\Billiards\Reports;

use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardSession;
use App\Models\Billiards\BilliardTable;
use App\Support\ClockFormat;
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
 * Las cuentas del salón por período.
 *
 * Separa el tiempo de los consumos en columnas distintas a propósito: es la
 * pregunta que un dueño de billar se hace todos los meses —si la plata está
 * en las mesas o en la nevera— y de la respuesta depende si compra otra
 * mesa o amplía la barra. Un solo total no la contesta.
 *
 * Las anuladas aparecen y se pueden filtrar, pero no suman: una cuenta
 * anulada no entró a la caja.
 */
class BilliardSessionsReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Cuentas por período';

    protected static ?string $navigationGroup = 'Billares';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Cuentas de billar';

    protected static string $view = 'filament.app.pages.reports.report-page';

    public ?array $filters = [];

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('billiards')) {
            return false;
        }

        return (bool) auth()->user()?->can('billiards.reports');
    }

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'hall_id' => null,
            'table_id' => null,
            'status' => null,
        ];

        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(5)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),

                    Forms\Components\Select::make('hall_id')
                        ->label('Sala')->placeholder('Todas')->live()
                        ->options(fn () => BilliardHall::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('name')->pluck('name', 'id')->all()),

                    Forms\Components\Select::make('table_id')
                        ->label('Mesa')->placeholder('Todas')->live()
                        ->options(fn () => BilliardTable::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (BilliardTable $m) => [$m->id => $m->etiqueta()])
                            ->all()),

                    Forms\Components\Select::make('status')
                        ->label('Estado')->placeholder('Todos')->live()
                        ->options(BilliardSession::STATUSES),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->baseQuery())
            ->defaultSort('started_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('started_at')->label('Abrió')
                    ->dateTime(ClockFormat::datetime())->sortable(),
                Tables\Columns\TextColumn::make('ended_at')->label('Cerró')
                    ->dateTime(ClockFormat::datetime())->placeholder('—'),
                Tables\Columns\TextColumn::make('table.code')->label('Mesa')
                    ->fontFamily('mono')->weight('semibold'),
                Tables\Columns\TextColumn::make('tableType.name')->label('Tipo')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('customer_label')->label('Cliente')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('players')->label('Jug.')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('charge_minutes')->label('Min cobrados')->alignEnd()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('time_total')->label('Tiempo')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('consumption_total')->label('Consumos')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('discount_total')->label('Cortesías')->money('COP')->alignEnd()
                    ->color('warning')->toggleable(),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd()->weight('semibold'),
                Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state) => BilliardSession::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        BilliardSession::STATUS_OPEN => 'info',
                        BilliardSession::STATUS_CLOSED => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('invoice_number')->label('Factura')
                    ->state(fn (BilliardSession $record) => $record->saleInvoice?->fullNumber() ?? '—')
                    ->fontFamily('mono')->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->resumen())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')->disabled(),
            ]);
    }

    protected function baseQuery(): Builder
    {
        $desde = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
        $hasta = $this->filters['to'] ?? now()->endOfMonth()->toDateString();

        return BilliardSession::query()
            ->whereDate('started_at', '>=', $desde)
            ->whereDate('started_at', '<=', $hasta)
            ->when($this->filters['hall_id'] ?? null, fn (Builder $q, $v) => $q->where('hall_id', $v))
            ->when($this->filters['table_id'] ?? null, fn (Builder $q, $v) => $q->where('table_id', $v))
            ->when($this->filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->with(['table:id,code', 'tableType:id,name', 'saleInvoice:id,prefix,number']);
    }

    /**
     * El resumen deja fuera las anuladas: una cuenta que se abrió por error
     * no es plata del salón, y sumarla inflaría el mes.
     */
    protected function resumen(): string
    {
        $base = $this->baseQuery()->where('status', '!=', BilliardSession::STATUS_CANCELLED);

        $cuantas = (clone $base)->count();
        $tiempo = (float) (clone $base)->sum('time_total');
        $consumos = (float) (clone $base)->sum('consumption_total');

        return sprintf(
            '%d cuenta%s — mesas $%s · consumos $%s · total $%s',
            $cuantas,
            $cuantas === 1 ? '' : 's',
            number_format($tiempo, 0, ',', '.'),
            number_format($consumos, 0, ',', '.'),
            number_format($tiempo + $consumos, 0, ',', '.'),
        );
    }
}
