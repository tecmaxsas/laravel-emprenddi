<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\CashRegisterSession;
use App\Models\Location;
use App\Models\User;
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

class CashClosingsPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Cierres de Caja';

    protected static ?string $navigationGroup = 'Reportes operativos';

    protected static ?string $title = 'Cierres de Caja Históricos';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('reports.cash_closings');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'location_id' => null,
            'cashier_user_id' => null,
            'only_with_difference' => false,
        ];
        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        return route('reports.export.cash_closings', array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'location_id' => $this->filters['location_id'] ?? null,
            'cashier_user_id' => $this->filters['cashier_user_id'] ?? null,
            'only_with_difference' => ($this->filters['only_with_difference'] ?? false) ? '1' : null,
        ]));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(5)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde (cierre)')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta (cierre)')->required()->live(),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->placeholder('Todas')
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live(),

                    Forms\Components\Select::make('cashier_user_id')
                        ->label('Cajero')
                        ->placeholder('Todos')
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live(),

                    Forms\Components\Toggle::make('only_with_difference')
                        ->label('Solo con diferencia')
                        ->helperText('Esconde turnos que cuadraron exactos')
                        ->live(),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
                $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();

                return CashRegisterSession::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('status', CashRegisterSession::STATUS_CLOSED)
                    ->whereDate('closed_at', '>=', $from)
                    ->whereDate('closed_at', '<=', $to)
                    ->when($this->filters['location_id'] ?? null, fn (Builder $q, $v) => $q->where('location_id', $v))
                    ->when($this->filters['cashier_user_id'] ?? null, fn (Builder $q, $v) => $q->where('cashier_user_id', $v))
                    ->when($this->filters['only_with_difference'] ?? false, fn (Builder $q) => $q
                        ->whereRaw('ABS(COALESCE(closing_difference, 0)) >= 0.01'))
                    ->with(['cashier:id,name', 'location:id,name', 'closedBy:id,name']);
            })
            ->defaultSort('closed_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->fontFamily('mono'),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->wrap(),
                Tables\Columns\TextColumn::make('cashier.name')->label('Cajero')->wrap(),

                Tables\Columns\TextColumn::make('opened_at')->label('Abrió')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('closed_at')->label('Cerró')->dateTime('Y-m-d H:i'),

                Tables\Columns\TextColumn::make('opening_amount')->label('Apertura')->money('COP')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('total_sales')->label('Ventas')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('invoice_count')->label('# Fact.')->alignEnd()->toggleable(),

                Tables\Columns\TextColumn::make('closing_expected')->label('Esperado')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('closing_counted')->label('Contado')->money('COP')->alignEnd(),

                Tables\Columns\TextColumn::make('closing_difference')
                    ->label('Diferencia')
                    ->state(fn (CashRegisterSession $r) => $r->closing_difference)
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold')
                    ->color(fn (CashRegisterSession $r) => match (true) {
                        abs((float) $r->closing_difference) < 0.01 => 'success',
                        (float) $r->closing_difference > 0 => 'info',     // sobrante
                        default => 'danger',                              // faltante
                    })
                    ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '').'$'.number_format((float) $state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('closing_notes')
                    ->label('Notas')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
