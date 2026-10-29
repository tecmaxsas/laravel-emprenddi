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
 * Quiénes se alojaron, con los datos del registro hotelero.
 *
 * No es un reporte de ventas: es el que hay que poder entregar si una
 * autoridad pregunta quién durmió en el hotel una noche, y el que da la base
 * para reportar los huéspedes extranjeros. Por eso el filtro de extranjeros
 * está de primero.
 */
class HotelGuestsReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Huéspedes alojados';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 81;

    protected static ?string $title = 'Huéspedes alojados';

    protected static string $view = 'filament.app.pages.hotel.reports.guests';

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
            'solo_extranjeros' => false,
        ];

        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Entró desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Entró hasta')->required()->live(),
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->placeholder('Todos')
                        ->live()
                        ->options(fn () => Hotel::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Forms\Components\Toggle::make('solo_extranjeros')
                        ->label('Solo extranjeros')
                        ->live()
                        ->helperText('Para el reporte a Migración.'),
                ]),
        ])->statePath('filters');
    }

    public function getExportUrl(): string
    {
        return route('hotel.reports.export.guests', array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'hotel_id' => $this->filters['hotel_id'] ?? null,
            'solo_extranjeros' => ! empty($this->filters['solo_extranjeros']) ? 1 : null,
        ]));
    }

    public function getViewData(): array
    {
        $filas = app(HotelReports::class)->huespedes($this->filters ?? []);

        return [
            'rows' => $filas,
            'totals' => [
                'huespedes' => $filas->count(),
                'extranjeros' => $filas->where('extranjero', true)->count(),
                'estadias' => $filas->pluck('folio')->unique()->count(),
            ],
            'from' => $this->filters['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $this->filters['to'] ?? now()->endOfMonth()->toDateString(),
        ];
    }
}
