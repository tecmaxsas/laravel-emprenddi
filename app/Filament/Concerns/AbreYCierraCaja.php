<?php

namespace App\Filament\Concerns;

use App\Models\CashRegisterSession;
use App\Models\Location;
use App\Services\Cash\CashSessionCloser;
use App\Services\Cash\CashSessionSummary;
use Filament\Notifications\Notification;

/**
 * El turno de caja, desde un terminal que no es el POS retail.
 *
 * Un parqueadero, un billar o un hotel no tienen por qué pasar por el POS
 * de retail para abrir su caja: desde que el POS retail redirige a los
 * terminales verticales, ese viaje de ida y vuelta solo parecía una
 * recarga de la página.
 *
 * Vive en un trait porque la regla —un turno abierto por cajero, el cierre
 * ciego si la empresa lo pidió, el permiso `pos.cash_close`— es la misma en
 * todos los terminales, y escrita en cada uno se queda vieja en todos menos
 * en el último que alguien tocó.
 */
trait AbreYCierraCaja
{
    public bool $openCashModalOpen = false;

    public ?int $openingLocationId = null;

    public ?float $openingAmount = 0.0;

    public string $openingNotes = '';

    public bool $showCloseSessionModal = false;

    public float $closingCounted = 0.0;

    public string $closingNotes = '';

    public function getOpenCashSessionProperty(): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('cashier_user_id', auth()->id())
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    public function getSessionTotalsProperty(): ?array
    {
        $turno = $this->openCashSession;

        return $turno ? app(CashSessionSummary::class)->compute($turno) : null;
    }

    /** @return array<int, string> */
    public function getOpeningLocationsProperty(): array
    {
        return Location::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function openCashModal(): void
    {
        if ($this->openCashSession) {
            Notification::make()->warning()->title('Ya tienes un turno abierto')->send();

            return;
        }

        $this->openingLocationId = Location::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->value('id');

        $this->openingAmount = 0.0;
        $this->openingNotes = '';
        $this->openCashModalOpen = true;
    }

    public function closeCashModal(): void
    {
        $this->openCashModalOpen = false;
    }

    public function openCashRegister(): void
    {
        if ($this->openCashSession) {
            Notification::make()->warning()->title('Ya tienes un turno abierto')->send();

            return;
        }

        if (! $this->openingLocationId) {
            Notification::make()->danger()->title('Selecciona la sede')->send();

            return;
        }

        $base = max(0, (float) ($this->openingAmount ?? 0));

        $turno = CashRegisterSession::create([
            'company_id' => auth()->user()->company_id,
            'location_id' => $this->openingLocationId,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => $base,
            'opening_notes' => trim($this->openingNotes) ?: null,
        ]);

        $this->openCashModalOpen = false;
        $this->openingNotes = '';

        unset($this->openCashSession, $this->sessionTotals);

        Notification::make()->success()
            ->title('Caja abierta')
            ->body('Sede '.$turno->location?->name.'. Base: $'.number_format($base, 0, ',', '.'))
            ->send();
    }

    public function openCloseSessionModal(): void
    {
        if (! $this->openCashSession) {
            Notification::make()->danger()->title('No hay caja abierta')->send();

            return;
        }

        $this->closingCounted = 0.0;
        $this->closingNotes = '';
        $this->showCloseSessionModal = true;
    }

    public function closeCloseSessionModal(): void
    {
        $this->showCloseSessionModal = false;
    }

    public function closeCashSession(): void
    {
        $turno = $this->openCashSession;

        if (! $turno) {
            Notification::make()->danger()->title('No hay caja abierta')->send();

            return;
        }

        if (! auth()->user()?->can('pos.cash_close')) {
            Notification::make()->danger()->title('Sin permiso para cerrar caja')->send();

            return;
        }

        try {
            $resultado = app(CashSessionCloser::class)->close(
                $turno,
                (float) ($this->closingCounted ?? 0),
                $this->closingNotes,
            );
        } catch (\Throwable $e) {
            Notification::make()->danger()->persistent()
                ->title('No se pudo cerrar la caja')->body($e->getMessage())->send();

            return;
        }

        $this->showCloseSessionModal = false;
        $this->closingCounted = 0.0;
        $this->closingNotes = '';

        // Invalidar las magic properties: sin esto el banner del turno sigue
        // pintando la caja que acaba de cerrarse.
        unset($this->openCashSession, $this->sessionTotals);

        // Cierre ciego: si la empresa lo activó, el cajero que no es admin
        // solo ve «cerrada». Saber el esperado antes de contar invita a
        // cuadrar el conteo contra él en vez de contar de verdad.
        $empresa = auth()->user()?->company;
        $ciego = (bool) data_get($empresa?->settings, 'pos.blind_cash_close', false)
            && ! auth()->user()->hasAnyRole(['admin', 'manager']);

        if ($ciego) {
            Notification::make()->success()->title('Caja cerrada')->send();

            return;
        }

        Notification::make()
            ->title('Caja cerrada')
            ->body(sprintf(
                'Esperado $%s · Contado $%s · Diferencia $%s',
                number_format($resultado['expected'], 2),
                number_format($resultado['counted'], 2),
                number_format($resultado['difference'], 2),
            ))
            ->{$resultado['difference'] === 0.0 ? 'success' : 'warning'}()
            ->send();
    }
}
