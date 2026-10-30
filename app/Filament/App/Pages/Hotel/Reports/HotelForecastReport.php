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
 * Pronóstico de ocupación: lo que viene.
 *
 * Todos los demás reportes del sistema miran hacia atrás —cuánto vendí, cuánto
 * ocupé—. Este es el único que mira hacia adelante, y es el que un gerente de
 * hotel abre el lunes por la mañana.
 *
 * La diferencia práctica: si el fin de semana viene en 40 % todavía hay tiempo
 * de hacer algo —una promoción, llamar a una agencia, soltar tarifa—. Si se
 * entera el lunes siguiente, cuando ya salió en el reporte de ocupación, lo
 * único que puede hacer es lamentarlo.
 */
class HotelForecastReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Pronóstico de ocupación';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 79;

    protected static ?string $title = 'Pronóstico de ocupación';

    protected static string $view = 'filament.app.pages.hotel.reports.forecast';

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
            'hotel_id' => Hotel::query()
                ->where('company_id', auth()->user()?->company_id)
                ->where('active', true)
                ->orderBy('id')
                ->value('id'),
            'desde' => now()->toDateString(),
            'dias' => 30,
        ];

        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Qué se mira')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('hotel_id')
                        ->label('Hotel')
                        ->native(false)
                        ->live()
                        ->options(fn () => Hotel::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),

                    Forms\Components\DatePicker::make('desde')
                        ->label('Desde')
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('dias')
                        ->label('Horizonte')
                        ->native(false)
                        ->live()
                        ->selectablePlaceholder(false)
                        ->options([
                            7 => 'Próximos 7 días',
                            14 => 'Próximos 14 días',
                            30 => 'Próximos 30 días',
                            60 => 'Próximos 60 días',
                        ]),
                ]),
        ])->statePath('filters');
    }

    public function getViewData(): array
    {
        $datos = app(HotelReports::class)->pronostico($this->filters ?? []);

        return [
            'rows' => $datos['filas'],
            'totals' => $datos['totales'],
            'available' => $datos['vendibles'],
        ];
    }
}
