<?php

namespace App\Filament\App\Pages\Parking;

use App\Models\Parking\ParkingSession;
use App\Services\Parking\ParkingSessionEngine;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pantalla de salida del parqueadero. El operario busca por placa, ve la
 * sesion activa con el monto calculado en vivo y procesa la salida (cobro
 * normal, ticket perdido o cancelación).
 *
 * El cobro/factura se completa en el commit 5; por ahora solo cierra la
 * sesion con el amount calculado.
 */
class ParkingExit extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-left-end-on-rectangle';
    protected static ?string $navigationLabel = 'Salida';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 2;
    protected static ?string $title = 'Registrar salida';

    protected static string $view = 'filament.app.pages.parking.exit';

    public ?array $data = ['plate' => ''];
    public ?ParkingSession $activeSession = null;
    public ?array $quote = null;
    public ?ParkingSession $lastClosedSession = null;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return (bool) auth()->user()?->can('parking.use');
    }

    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Buscar vehículo')
                ->columns(['default' => 1, 'md' => 4])
                ->schema([
                    Forms\Components\TextInput::make('plate')
                        ->label('Placa')
                        ->autofocus()
                        ->required()
                        ->maxLength(20)
                        ->placeholder('ABC123')
                        ->extraInputAttributes(['style' => 'text-transform: uppercase; font-size: 18px; font-weight: 700; letter-spacing: 2px;'])
                        ->columnSpan(3),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('search')
                            ->label('Buscar')
                            ->icon('heroicon-o-magnifying-glass')
                            ->action('searchPlate'),
                    ])->columnSpan(1),
                ]),
        ])->statePath('data');
    }

    public function searchPlate(): void
    {
        $plate = strtoupper(preg_replace('/\s+/', '', trim((string) ($this->data['plate'] ?? ''))));
        if ($plate === '') {
            $this->errorNotif('Ingresa una placa');
            return;
        }

        $session = ParkingSession::query()
            ->with(['parkingLot', 'vehicleType'])
            ->where('plate', $plate)
            ->where('status', ParkingSession::STATUS_ACTIVE)
            ->orderByDesc('entry_at')
            ->first();

        if (! $session) {
            $this->activeSession = null;
            $this->quote = null;
            $this->errorNotif('No hay sesión activa para esta placa');
            return;
        }

        $this->activeSession = $session;
        $this->refreshQuote();
    }

    public function refreshQuote(): void
    {
        if (! $this->activeSession) return;
        $this->quote = app(ParkingSessionEngine::class)->quote($this->activeSession);
    }

    public function processExit(): void
    {
        if (! $this->activeSession) return;
        try {
            $closed = app(ParkingSessionEngine::class)->checkOut($this->activeSession);
            $this->lastClosedSession = $closed->load(['parkingLot', 'vehicleType']);
            $this->activeSession = null;
            $this->quote = null;
            $this->data['plate'] = '';
            $this->form->fill($this->data);

            Notification::make()
                ->title("Salida registrada · {$closed->plate}")
                ->body('Total a cobrar: $'.number_format((float) $closed->amount, 0, ',', '.'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->errorNotif('No se pudo registrar la salida', $e->getMessage());
        }
    }

    public function processLostTicket(): void
    {
        if (! $this->activeSession) return;
        try {
            $closed = app(ParkingSessionEngine::class)->lostTicket($this->activeSession);
            $this->lastClosedSession = $closed->load(['parkingLot', 'vehicleType']);
            $this->activeSession = null;
            $this->quote = null;
            $this->data['plate'] = '';
            $this->form->fill($this->data);

            Notification::make()
                ->title("Ticket perdido · {$closed->plate}")
                ->body('Cobro: $'.number_format((float) $closed->amount, 0, ',', '.'))
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            $this->errorNotif('No se pudo procesar ticket perdido', $e->getMessage());
        }
    }

    public function cancelSession(): void
    {
        if (! $this->activeSession) return;
        $reason = trim((string) ($this->data['cancel_reason'] ?? ''));
        if ($reason === '') {
            $this->errorNotif('Indica el motivo de la anulación');
            return;
        }
        try {
            app(ParkingSessionEngine::class)->cancel($this->activeSession, $reason);
            $this->activeSession = null;
            $this->quote = null;
            $this->data['plate'] = '';
            $this->data['cancel_reason'] = '';
            $this->form->fill($this->data);

            Notification::make()
                ->title('Sesión anulada')
                ->body($reason)
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            $this->errorNotif('No se pudo anular la sesión', $e->getMessage());
        }
    }

    protected function errorNotif(string $title, ?string $body = null): void
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();
    }
}
