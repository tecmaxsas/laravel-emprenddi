<?php

namespace App\Filament\App\Pages\Parking;

use App\Models\Account;
use App\Models\Parking\ParkingSession;
use App\Models\PaymentMethod;
use App\Services\Parking\ParkingBillingEngine;
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

    public ?array $data = ['plate' => '', 'payment_method' => 'cash', 'account_id' => null, 'invoice_kind' => 'pos'];
    public ?ParkingSession $activeSession = null;
    public ?array $quote = null;
    public ?ParkingSession $lastClosedSession = null;
    public ?\App\Models\SaleInvoice $lastInvoice = null;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return (bool) auth()->user()?->can('parking.use');
    }

    public function mount(): void
    {
        // Cuenta de caja por defecto si existe (1105 — Caja general)
        $this->data['account_id'] = Account::query()
            ->where('accepts_movements', true)->where('active', true)
            ->where(function ($q) {
                $q->where('code', '110505')->orWhere('code', '1105');
            })
            ->orderBy('code')->value('id');
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

            Forms\Components\Section::make('Cobro')
                ->description('Datos para emitir la factura POS o electrónica al cerrar la sesión.')
                ->columns(3)
                ->visible(fn () => $this->activeSession !== null
                    && (float) ($this->quote['amount'] ?? 0) > 0
                    && ! $this->activeSession?->parking_membership_id)
                ->schema([
                    Forms\Components\Select::make('payment_method')
                        ->label('Medio de pago')->native(false)->required()
                        ->options(fn () => $this->paymentMethodOptions())
                        ->default('cash'),

                    Forms\Components\Select::make('account_id')
                        ->label('Cuenta contable')->native(false)->required()->searchable()
                        ->options(fn () => Account::query()
                            ->where('accepts_movements', true)->where('active', true)
                            ->where(function ($q) {
                                $q->where('code', 'like', '11%')
                                    ->orWhere('code', 'like', '12%');
                            })
                            ->orderBy('code')->limit(50)->get()
                            ->mapWithKeys(fn ($a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all())
                        ->helperText('Caja, banco o cuenta donde entra el dinero.'),

                    Forms\Components\Select::make('invoice_kind')
                        ->label('Tipo de factura')->native(false)->required()
                        ->options([
                            'pos' => 'POS (no electrónica)',
                            'electronic' => 'Electrónica (DIAN)',
                        ])->default('pos'),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente (opcional)')->native(false)->searchable()
                        ->placeholder('Consumidor Final')
                        ->getSearchResultsUsing(fn (string $search) => \App\Models\ThirdParty::query()
                            ->where('is_customer', true)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                  ->orWhere('document_number', 'ilike', "%{$search}%");
                            })
                            ->orderBy('name')->limit(20)->get()
                            ->mapWithKeys(fn ($c) => [$c->id => trim(($c->document_number ?: '—').' · '.$c->name)])
                            ->all())
                        ->getOptionLabelUsing(fn ($v) => \App\Models\ThirdParty::find($v)?->name)
                        ->columnSpan(3),
                ]),
        ])->statePath('data');
    }

    protected function paymentMethodOptions(): array
    {
        // Si la empresa tiene metodos de pago definidos, los listamos; si
        // no, opciones genericas.
        $configured = PaymentMethod::query()
            ->where('active', true)
            ->orderBy('name')->pluck('name', 'code')->all();
        if (! empty($configured)) return $configured;
        return [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'credit' => 'A crédito',
        ];
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
        // Tipo factura por defecto desde el parqueadero
        $this->data['invoice_kind'] = $session->parkingLot?->default_invoice_kind ?: 'pos';
        $this->form->fill($this->data);
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
            $invoice = $this->maybeIssueInvoice($closed);

            $this->lastClosedSession = $closed->load(['parkingLot', 'vehicleType']);
            $this->lastInvoice = $invoice;
            $this->activeSession = null;
            $this->quote = null;
            $this->data['plate'] = '';
            $this->form->fill($this->data);

            $msg = 'Total: $'.number_format((float) $closed->amount, 0, ',', '.');
            if ($invoice) {
                $msg .= ' · Factura '.$invoice->fullNumber();
            }
            Notification::make()
                ->title("Salida registrada · {$closed->plate}")
                ->body($msg)
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
            $invoice = $this->maybeIssueInvoice($closed);

            $this->lastClosedSession = $closed->load(['parkingLot', 'vehicleType']);
            $this->lastInvoice = $invoice;
            $this->activeSession = null;
            $this->quote = null;
            $this->data['plate'] = '';
            $this->form->fill($this->data);

            $msg = 'Cobro: $'.number_format((float) $closed->amount, 0, ',', '.');
            if ($invoice) {
                $msg .= ' · Factura '.$invoice->fullNumber();
            }
            Notification::make()
                ->title("Ticket perdido · {$closed->plate}")
                ->body($msg)
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            $this->errorNotif('No se pudo procesar ticket perdido', $e->getMessage());
        }
    }

    /**
     * Si la sesion cerrada tiene monto > 0 (sin mensualidad), emite factura.
     * Las sesiones con membership tienen amount=0 y se saltan.
     */
    protected function maybeIssueInvoice(ParkingSession $closed): ?\App\Models\SaleInvoice
    {
        if ((float) $closed->amount <= 0 || $closed->parking_membership_id) {
            return null;
        }
        $accountId = (int) ($this->data['account_id'] ?? 0);
        if ($accountId <= 0) {
            $this->errorNotif(
                'Falta cuenta contable del cobro',
                'Selecciona en qué cuenta entra el dinero antes de procesar la salida.',
            );
            throw new \RuntimeException('Sin cuenta de cobro seleccionada.');
        }

        return app(ParkingBillingEngine::class)->issueForSession($closed, [
            'invoice_kind' => $this->data['invoice_kind'] ?? 'pos',
            'payment_method' => $this->data['payment_method'] ?? 'cash',
            'account_id' => $accountId,
            'paid_amount' => (float) $closed->amount,
            'third_party_id' => $this->data['third_party_id'] ?? null,
            'reference' => 'Parqueo '.$closed->plate,
        ]);
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
