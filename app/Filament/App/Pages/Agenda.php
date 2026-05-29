<?php

namespace App\Filament\App\Pages;

use App\Models\Appointment;
use App\Support\AppointmentsSettings;
use Carbon\Carbon;
use Filament\Pages\Page;

/**
 * Vista de calendario semanal de la agenda de citas. Muestra 7 columnas
 * (lunes a domingo) con las citas de cada día como tarjetas, coloreadas
 * por estado. Navegación por semanas. Cada tarjeta enlaza a editar la cita.
 *
 * Pertenece al módulo opcional Citas (AppointmentsSettings).
 */
class Agenda extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Agenda';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 69;
    protected static ?string $title = 'Agenda';

    protected static string $view = 'filament.app.pages.agenda';

    public string $anchor;

    public static function canAccess(): bool
    {
        if (! AppointmentsSettings::moduleActive()) {
            return false;
        }
        return (bool) auth()->user()?->can('appointments.view');
    }

    public function mount(): void
    {
        $this->anchor = now()->toDateString();
    }

    public function prevWeek(): void
    {
        $this->anchor = Carbon::parse($this->anchor)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->anchor = Carbon::parse($this->anchor)->addWeek()->toDateString();
    }

    public function goToday(): void
    {
        $this->anchor = now()->toDateString();
    }

    public function getViewData(): array
    {
        $start = Carbon::parse($this->anchor)->startOfWeek(Carbon::MONDAY);
        $end = (clone $start)->endOfWeek(Carbon::SUNDAY);

        $appointments = Appointment::query()
            ->where('company_id', auth()->user()->company_id)
            ->whereBetween('starts_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->with([
                'client:id,name',
                'employee:id,first_name,middle_name,last_name,second_last_name',
                'service:id,name',
            ])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Appointment $a) => $a->starts_at->toDateString());

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = (clone $start)->addDays($i);
            $key = $d->toDateString();
            $days[] = [
                'date' => $d,
                'key' => $key,
                'is_today' => $d->isToday(),
                'appointments' => $appointments->get($key, collect()),
            ];
        }

        return [
            'weekStart' => $start,
            'weekEnd' => $end,
            'days' => $days,
        ];
    }
}
