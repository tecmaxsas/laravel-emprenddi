<?php

namespace App\Filament\App\Pages;

use App\Models\Appointment;
use App\Support\AppointmentsSettings;
use Carbon\Carbon;
use Filament\Pages\Page;

/**
 * Vista de calendario de la agenda de citas. Dos modos:
 *  - mes: grid mensual completo (semanas como filas), citas como chips.
 *  - semana: 7 columnas (lunes a domingo) con tarjetas detalladas.
 * Cada cita enlaza a editarla. Pertenece al módulo opcional Citas.
 */
class Agenda extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Agenda';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 70;
    protected static ?string $title = 'Agenda';

    protected static string $view = 'filament.app.pages.agenda';

    public string $anchor;
    public string $mode = 'month';

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

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['month', 'week'], true) ? $mode : 'month';
    }

    public function prev(): void
    {
        $d = Carbon::parse($this->anchor);
        $this->anchor = ($this->mode === 'month' ? $d->subMonthNoOverflow() : $d->subWeek())->toDateString();
    }

    public function next(): void
    {
        $d = Carbon::parse($this->anchor);
        $this->anchor = ($this->mode === 'month' ? $d->addMonthNoOverflow() : $d->addWeek())->toDateString();
    }

    public function goToday(): void
    {
        $this->anchor = now()->toDateString();
    }

    /** Salta a la vista de semana anclada en el día indicado. */
    public function openDay(string $date): void
    {
        $this->anchor = Carbon::parse($date)->toDateString();
        $this->mode = 'week';
    }

    public function getViewData(): array
    {
        return $this->mode === 'week'
            ? $this->weekData()
            : $this->monthData();
    }

    protected function weekData(): array
    {
        $start = Carbon::parse($this->anchor)->startOfWeek(Carbon::MONDAY);
        $end = (clone $start)->endOfWeek(Carbon::SUNDAY);

        $byDay = $this->fetchAppointments($start, $end);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = (clone $start)->addDays($i);
            $key = $d->toDateString();
            $days[] = [
                'date' => $d,
                'key' => $key,
                'is_today' => $d->isToday(),
                'in_month' => true,
                'appointments' => $byDay->get($key, collect()),
            ];
        }

        return [
            'mode' => 'week',
            'periodLabel' => $start->locale('es')->isoFormat('D [de] MMMM')
                .' — '.$end->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'days' => $days,
            'weeks' => null,
        ];
    }

    protected function monthData(): array
    {
        $monthStart = Carbon::parse($this->anchor)->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();
        $gridStart = (clone $monthStart)->startOfWeek(Carbon::MONDAY);
        $gridEnd = (clone $monthEnd)->endOfWeek(Carbon::SUNDAY);

        $byDay = $this->fetchAppointments($gridStart, $gridEnd);

        $weeks = [];
        $cursor = clone $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $key = $cursor->toDateString();
                $week[] = [
                    'date' => $cursor->copy(),
                    'key' => $key,
                    'in_month' => $cursor->month === $monthStart->month,
                    'is_today' => $cursor->isToday(),
                    'appointments' => $byDay->get($key, collect()),
                ];
                $cursor->addDay();
            }
            $weeks[] = $week;
        }

        return [
            'mode' => 'month',
            'periodLabel' => ucfirst($monthStart->locale('es')->isoFormat('MMMM [de] YYYY')),
            'days' => null,
            'weeks' => $weeks,
        ];
    }

    protected function fetchAppointments(Carbon $from, Carbon $to)
    {
        return Appointment::query()
            ->where('company_id', auth()->user()->company_id)
            ->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with([
                'client:id,name',
                'employee:id,first_name,middle_name,last_name,second_last_name',
                'service:id,name',
            ])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Appointment $a) => $a->starts_at->toDateString());
    }
}
