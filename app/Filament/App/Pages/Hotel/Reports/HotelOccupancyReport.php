<?php

namespace App\Filament\App\Pages\Hotel\Reports;

use App\Models\Hotel\Hotel;
use App\Services\Hotel\HotelReports;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Ocupación, ADR y RevPAR, día por día.
 *
 * Es el reporte que un hotelero mira todas las mañanas, y no es «cuánto
 * facturé»: un hotel con tarifa altísima y 20% de ocupación gana menos que
 * uno con tarifa media y 90%, y el total facturado no lo dice.
 *
 * Los números los calcula HotelReports, el mismo servicio que alimenta el
 * Excel: así la cifra de la pantalla y la del archivo no pueden discrepar.
 */
class HotelOccupancyReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Ocupación e ingresos';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Ocupación e ingresos del hotel';

    protected static string $view = 'filament.app.pages.hotel.reports.occupancy';

    public ?array $filters = [];

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('hotel')) {
            return false;
        }

        return (bool) auth()->user()?->can('hotel.reports');
    }

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'hotel_id' => null,
        ];

        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(3)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->placeholder('Todos')
                        ->live()
                        ->options(fn () => Hotel::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                ]),
        ])->statePath('filters');
    }

    public function getExportUrl(): string
    {
        return route('hotel.reports.export.occupancy', $this->filtrosLimpios());
    }

    public function getStaysExportUrl(): string
    {
        return route('hotel.reports.export.revenue', $this->filtrosLimpios());
    }

    public function getViewData(): array
    {
        $datos = app(HotelReports::class)->ocupacion($this->filters ?? []);

        return [
            'rows' => $datos['filas'],
            'totals' => $datos['totales'],
            'available' => $datos['disponibles'],
            'from' => $this->filters['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $this->filters['to'] ?? now()->endOfMonth()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    protected function filtrosLimpios(): array
    {
        return array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'hotel_id' => $this->filters['hotel_id'] ?? null,
        ]);
    }
}
