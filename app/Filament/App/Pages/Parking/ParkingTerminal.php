<?php

namespace App\Filament\App\Pages\Parking;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingMembership;
use App\Models\Parking\ParkingSession;
use App\Models\Parking\ParkingSpace;
use App\Models\Parking\VehicleType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Services\Parking\ParkingBillingEngine;
use App\Services\Parking\ParkingProductProvisioner;
use App\Services\Parking\ParkingSessionEngine;
use App\Support\ModuleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Terminal unificado de parqueadero estilo POS.
 *
 * Una sola pantalla: el operario elige parqueadero, ve el mapa de espacios
 * por zona y opera con clic sobre los tiles (entrada al hacer clic en uno
 * libre, salida al hacer clic en uno ocupado) o con un input de escaneo
 * (QR del ticket o placa) que tambien dispara las modales correspondientes.
 *
 * El estado de la modal se controla con $mode:
 *   idle    sin modal
 *   entry   capturando datos para crear sesion en el espacio seleccionado
 *   exit    mostrando cobro de la sesion activa seleccionada
 *
 * Las paginas antiguas ParkingEntry y ParkingExit quedan accesibles por URL
 * pero ocultas del sidebar — esta es la nueva pantalla principal.
 */
class ParkingTerminal extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Terminal';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'parking';
    protected static ?string $title = 'Terminal de Parqueadero';

    protected static string $view = 'filament.app.pages.parking.terminal';

    public ?int $parkingLotId = null;

    public string $scanInput = '';

    /** idle | entry | exit */
    public string $mode = 'idle';

    public ?int $selectedSpaceId = null;
    public ?int $activeSessionId = null;

    public array $entryForm = [];
    public array $exitForm = [];
    public ?array $quote = null;
    public ?int $printTicketId = null;

    /** Items adicionales agregados a la salida (productos/servicios) */
    public array $exitExtras = [];
    public string $extraSearch = '';

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return (bool) auth()->user()?->can('parking.use');
    }

    public function mount(): void
    {
        $this->parkingLotId = ParkingLot::query()
            ->where('active', true)
            ->orderBy('id')
            ->value('id');
        $this->resetEntryForm();
        $this->resetExitForm();
    }

    /* ------------------------------------------------------------------ */
    /*  Properties computadas para la vista                                */
    /* ------------------------------------------------------------------ */

    public function getLotsProperty()
    {
        return ParkingLot::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'default_invoice_kind']);
    }

    public function getCurrentLotProperty(): ?ParkingLot
    {
        return $this->parkingLotId
            ? ParkingLot::find($this->parkingLotId)
            : null;
    }

    public function getVehicleTypesProperty()
    {
        return VehicleType::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'icon', 'code']);
    }

    public function getSpacesByZoneProperty(): array
    {
        if (! $this->parkingLotId) return [];

        $spaces = ParkingSpace::query()
            ->where('parking_lot_id', $this->parkingLotId)
            ->with(['vehicleType:id,name,code', 'activeSession:id,parking_space_id,plate,entry_at'])
            ->orderBy('zone')
            ->orderBy('code')
            ->get();

        return $spaces
            ->groupBy(fn ($space) => $space->zone ?: 'Sin zona')
            ->all();
    }

    public function getActiveWithoutSpaceProperty()
    {
        if (! $this->parkingLotId) return collect();
        return ParkingSession::query()
            ->where('parking_lot_id', $this->parkingLotId)
            ->where('status', ParkingSession::STATUS_ACTIVE)
            ->whereNull('parking_space_id')
            ->orderByDesc('entry_at')
            ->limit(20)
            ->get(['id', 'plate', 'vehicle_type_id', 'entry_at', 'parking_membership_id']);
    }

    public function getOccupancyStatsProperty(): array
    {
        if (! $this->parkingLotId) {
            return ['total' => 0, 'free' => 0, 'occupied' => 0, 'other' => 0];
        }
        $counts = ParkingSpace::query()
            ->where('parking_lot_id', $this->parkingLotId)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
        $total = array_sum($counts);
        return [
            'total' => $total,
            'free' => (int) ($counts[ParkingSpace::STATUS_FREE] ?? 0),
            'occupied' => (int) ($counts[ParkingSpace::STATUS_OCCUPIED] ?? 0),
            'other' => $total - (int) ($counts[ParkingSpace::STATUS_FREE] ?? 0)
                              - (int) ($counts[ParkingSpace::STATUS_OCCUPIED] ?? 0),
        ];
    }

    public function getOpenCashSessionProperty(): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('cashier_user_id', auth()->id())
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    public function getSelectedSpaceProperty(): ?ParkingSpace
    {
        return $this->selectedSpaceId ? ParkingSpace::find($this->selectedSpaceId) : null;
    }

    public function getActiveSessionProperty(): ?ParkingSession
    {
        if (! $this->activeSessionId) return null;
        return ParkingSession::with(['vehicleType:id,name', 'parkingLot:id,name', 'parkingMembership:id,name,end_date'])
            ->find($this->activeSessionId);
    }

    public function getPaymentMethodOptionsProperty(): array
    {
        $companyId = auth()->user()?->company_id;
        $configured = PaymentMethod::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'code')->all();
        if (! empty($configured)) return $configured;
        return [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'credit' => 'A crédito',
        ];
    }

    public function getAccountOptionsProperty(): array
    {
        $companyId = auth()->user()?->company_id;
        return Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)->where('active', true)
            ->where(function ($q) {
                $q->where('code', 'like', '11%')->orWhere('code', 'like', '12%');
            })
            ->orderBy('code')->limit(50)->get()
            ->mapWithKeys(fn ($a) => [$a->id => "{$a->code} — {$a->name}"])
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Reset helpers                                                      */
    /* ------------------------------------------------------------------ */

    protected function resetEntryForm(): void
    {
        $this->entryForm = [
            'vehicle_type_id' => VehicleType::query()
                ->where('active', true)
                ->where('code', VehicleType::CODE_CAR)
                ->value('id'),
            'plate' => '',
            'notes' => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Extras (productos/servicios adicionales)                           */
    /* ------------------------------------------------------------------ */

    public function getProductSearchResultsProperty()
    {
        $q = trim($this->extraSearch);
        if (strlen($q) < 2) return collect();
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        if (! $companyId) return collect();

        return Product::query()
            ->where('company_id', $companyId)  // defense in depth — no confiar solo en el scope
            ->where('is_sellable', true)
            ->where('active', true)
            ->where('code', '!=', ParkingProductProvisioner::CODE)
            ->where(function ($builder) use ($q) {
                $builder->where('name', 'ilike', "%{$q}%")
                    ->orWhere('code', 'ilike', "%{$q}%");
            })
            ->orderBy('name')->limit(12)
            ->get(['id', 'code', 'name', 'default_sale_price', 'default_sale_tax_id']);
    }

    public function addExtra(int $productId): void
    {
        // Si ya esta en la lista, solo incrementa cantidad
        foreach ($this->exitExtras as $i => $extra) {
            if ((int) $extra['product_id'] === $productId) {
                $this->exitExtras[$i]['quantity']++;
                $this->extraSearch = '';
                return;
            }
        }

        // Solo productos de la misma empresa del usuario
        $product = Product::query()
            ->where('id', $productId)
            ->where('company_id', auth()->user()?->company_id)
            ->first();
        if (! $product) return;
        $tax = $product->default_sale_tax_id ? Tax::find($product->default_sale_tax_id) : null;

        $this->exitExtras[] = [
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => (float) $product->default_sale_price,
            'tax_id' => $tax?->id,
            'tax_rate' => $tax ? (float) $tax->rate : 0,
        ];
        $this->extraSearch = '';
    }

    public function removeExtra(int $index): void
    {
        if (! isset($this->exitExtras[$index])) return;
        unset($this->exitExtras[$index]);
        $this->exitExtras = array_values($this->exitExtras);
    }

    public function adjustExtraQty(int $index, int $delta): void
    {
        if (! isset($this->exitExtras[$index])) return;
        $new = max(1, (int) $this->exitExtras[$index]['quantity'] + $delta);
        $this->exitExtras[$index]['quantity'] = $new;
    }

    public function getExtrasTotalProperty(): float
    {
        return array_sum(array_map(
            fn ($e) => (int) ($e['quantity'] ?? 0) * (float) ($e['unit_price'] ?? 0),
            $this->exitExtras,
        ));
    }

    public function getGrandTotalProperty(): float
    {
        $parking = (float) ($this->quote['amount'] ?? 0);
        return round($parking + $this->extrasTotal, 2);
    }

    /* ------------------------------------------------------------------ */

    protected function resetExitForm(): void
    {
        $companyId = auth()->user()?->company_id;
        $defaultAccount = Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)->where('active', true)
            ->where(function ($q) {
                $q->where('code', '110505')->orWhere('code', '1105');
            })
            ->orderBy('code')->value('id');

        $this->exitForm = [
            'payment_method' => 'cash',
            'account_id' => $defaultAccount,
            'invoice_kind' => $this->currentLot?->default_invoice_kind ?: 'pos',
            'third_party_id' => null,
            'third_party_label' => null,
            'cancel_reason' => '',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Acciones del operario                                              */
    /* ------------------------------------------------------------------ */

    public function changeLot(int $lotId): void
    {
        $this->parkingLotId = $lotId;
        $this->closeModal();
    }

    /**
     * Click sobre un tile del mapa. Resuelve si abre modal de entrada o de
     * salida segun el estado del espacio.
     */
    public function selectSpace(int $spaceId): void
    {
        $space = ParkingSpace::query()
            ->where('id', $spaceId)
            ->where('parking_lot_id', $this->parkingLotId)
            ->first();
        if (! $space) return;

        $this->selectedSpaceId = $space->id;

        if ($space->isFree()) {
            $this->mode = 'entry';
            $this->resetEntryForm();
            $this->activeSessionId = null;
            $this->quote = null;
            return;
        }

        if ($space->status === ParkingSpace::STATUS_OCCUPIED) {
            $session = ParkingSession::query()
                ->where('parking_space_id', $space->id)
                ->where('status', ParkingSession::STATUS_ACTIVE)
                ->latest('entry_at')
                ->first();
            if (! $session) {
                $this->notify('warning', 'Espacio en estado inconsistente',
                    'El espacio está marcado como ocupado pero no hay sesión activa. Anula o ajusta el estado desde Espacios.');
                $this->selectedSpaceId = null;
                return;
            }
            $this->openExitFor($session->id);
            return;
        }

        $this->notify('info', 'Espacio no disponible',
            'Estado actual: '.(ParkingSpace::STATUSES[$space->status] ?? $space->status));
        $this->selectedSpaceId = null;
    }

    /**
     * Click sobre una sesion activa de la lista "sin espacio".
     */
    public function selectSession(int $sessionId): void
    {
        $this->openExitFor($sessionId);
    }

    protected function openExitFor(int $sessionId): void
    {
        $session = ParkingSession::find($sessionId);
        if (! $session) return;
        $this->parkingLotId = $session->parking_lot_id;
        $this->activeSessionId = $session->id;
        $this->selectedSpaceId = $session->parking_space_id;
        $this->mode = 'exit';
        $this->resetExitForm();
        $this->refreshQuote();
    }

    /**
     * Input del escaner. Acepta:
     *   PK<id>     -> ticket QR generado al imprimir
     *   <plate>    -> texto libre = busca sesion activa por placa en el lot
     */
    public function handleScan(): void
    {
        $input = trim($this->scanInput);
        $this->scanInput = '';
        if ($input === '') return;

        if (preg_match('/^PK(\d+)$/i', $input, $m)) {
            $session = ParkingSession::query()
                ->where('id', (int) $m[1])
                ->where('status', ParkingSession::STATUS_ACTIVE)
                ->first();
            if ($session) {
                $this->openExitFor($session->id);
                return;
            }
            $this->notify('warning', 'Ticket no encontrado',
                "No hay sesión activa para el ticket {$input}.");
            return;
        }

        $plate = strtoupper(preg_replace('/\s+/', '', $input));
        $session = ParkingSession::query()
            ->where('plate', $plate)
            ->where('status', ParkingSession::STATUS_ACTIVE)
            ->orderByDesc('entry_at')
            ->first();
        if ($session) {
            $this->openExitFor($session->id);
            return;
        }
        $this->notify('warning', 'Sin sesión activa', "No hay sesión activa para '{$plate}'.");
    }

    public function refreshQuote(): void
    {
        if (! $this->activeSessionId) return;
        $session = ParkingSession::find($this->activeSessionId);
        if ($session) {
            $this->quote = app(ParkingSessionEngine::class)->quote($session);
        }
    }

    public function registerEntry(): void
    {
        if ($this->mode !== 'entry') return;

        try {
            $session = app(ParkingSessionEngine::class)->checkIn([
                'parking_lot_id' => $this->parkingLotId,
                'vehicle_type_id' => $this->entryForm['vehicle_type_id'] ?? null,
                'parking_space_id' => $this->selectedSpaceId,
                'plate' => $this->entryForm['plate'] ?? '',
                'notes' => $this->entryForm['notes'] ?? null,
            ]);

            $ticketId = $session->id;
            $this->closeModal();

            Notification::make()
                ->title("✓ Entrada · {$session->plate}")
                ->body('Ticket #'.str_pad((string) $session->id, 6, '0', STR_PAD_LEFT)
                    .' · '.$session->entry_at->format('H:i'))
                ->success()->send();

            // Dispara JS para abrir ventana de impresion del ticket
            $this->printTicketId = $ticketId;
            $this->dispatch('parking-ticket-ready', sessionId: $ticketId);
        } catch (\Throwable $e) {
            $this->notify('danger', 'No se pudo registrar la entrada', $e->getMessage());
        }
    }

    /**
     * Atajo: entrada plate-only (sin espacio asignado) desde el panel
     * lateral. Util para parqueaderos sin slots configurados.
     */
    public function openQuickEntry(): void
    {
        $this->selectedSpaceId = null;
        $this->mode = 'entry';
        $this->resetEntryForm();
    }

    public function processExit(): void
    {
        $session = $this->activeSession;
        if (! $session) return;

        try {
            $closed = app(ParkingSessionEngine::class)->checkOut($session);
            $invoice = $this->maybeIssueInvoice($closed);
            $charged = $invoice ? (float) $invoice->total : (float) $closed->amount;

            $this->closeModal();

            $msg = 'Total: $'.number_format($charged, 0, ',', '.');
            if ($invoice) {
                $msg .= ' · Factura '.$invoice->fullNumber();
            }
            Notification::make()
                ->title("✓ Salida · {$closed->plate}")
                ->body($msg)
                ->success()->send();

            // Imprimir factura POS en nueva ventana (mismo flujo que POS regular)
            if ($invoice) {
                $this->dispatch('parking-invoice-ready', invoiceId: $invoice->id);
            }
        } catch (\Throwable $e) {
            $this->notify('danger', 'No se pudo registrar la salida', $e->getMessage());
        }
    }

    public function processLostTicket(): void
    {
        $session = $this->activeSession;
        if (! $session) return;

        try {
            $closed = app(ParkingSessionEngine::class)->lostTicket($session);
            $invoice = $this->maybeIssueInvoice($closed);
            $charged = $invoice ? (float) $invoice->total : (float) $closed->amount;

            $this->closeModal();

            $msg = 'Cobro: $'.number_format($charged, 0, ',', '.');
            if ($invoice) {
                $msg .= ' · Factura '.$invoice->fullNumber();
            }
            Notification::make()
                ->title("⚠ Ticket perdido · {$closed->plate}")
                ->body($msg)
                ->warning()->send();

            if ($invoice) {
                $this->dispatch('parking-invoice-ready', invoiceId: $invoice->id);
            }
        } catch (\Throwable $e) {
            $this->notify('danger', 'No se pudo procesar el ticket perdido', $e->getMessage());
        }
    }

    public function cancelSession(): void
    {
        $session = $this->activeSession;
        if (! $session) return;
        $reason = trim((string) ($this->exitForm['cancel_reason'] ?? ''));
        if ($reason === '') {
            $this->notify('warning', 'Motivo requerido', 'Indica el motivo de la anulación.');
            return;
        }
        try {
            app(ParkingSessionEngine::class)->cancel($session, $reason);
            $this->closeModal();
            Notification::make()->title('Sesión anulada')->body($reason)->warning()->send();
        } catch (\Throwable $e) {
            $this->notify('danger', 'No se pudo anular', $e->getMessage());
        }
    }

    protected function maybeIssueInvoice(ParkingSession $closed): ?\App\Models\SaleInvoice
    {
        $parkingCharge = $closed->parking_membership_id ? 0 : (float) $closed->amount;
        $extrasTotal = $this->extrasTotal;
        $grandTotal = round($parkingCharge + $extrasTotal, 2);

        // Nada que facturar: parqueo cubierto/cero + sin extras
        if ($grandTotal <= 0) {
            return null;
        }

        $accountId = (int) ($this->exitForm['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \RuntimeException('Selecciona la cuenta contable del cobro.');
        }

        return app(ParkingBillingEngine::class)->issueForSession($closed, [
            'invoice_kind' => $this->exitForm['invoice_kind'] ?? 'pos',
            'payment_method' => $this->exitForm['payment_method'] ?? 'cash',
            'account_id' => $accountId,
            'paid_amount' => $grandTotal,
            'third_party_id' => $this->exitForm['third_party_id'] ?? null,
            'reference' => 'Parqueo '.$closed->plate,
            'extras' => $this->exitExtras,
        ]);
    }

    public function closeModal(): void
    {
        $this->mode = 'idle';
        $this->selectedSpaceId = null;
        $this->activeSessionId = null;
        $this->quote = null;
        $this->exitExtras = [];
        $this->extraSearch = '';
        $this->resetEntryForm();
        $this->resetExitForm();
    }

    public function searchCustomer(string $query): array
    {
        if (strlen(trim($query)) < 2) return [];
        $companyId = auth()->user()?->company_id;
        return ThirdParty::query()
            ->where('company_id', $companyId)
            ->where('is_customer', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                  ->orWhere('document_number', 'ilike', "%{$query}%");
            })
            ->orderBy('name')->limit(8)->get(['id', 'name', 'document_number'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'label' => trim(($c->document_number ?: '—').' · '.$c->name),
            ])
            ->all();
    }

    protected function notify(string $type, string $title, ?string $body = null): void
    {
        $n = Notification::make()->title($title);
        if ($body) $n->body($body);
        match ($type) {
            'success' => $n->success(),
            'danger' => $n->danger()->persistent(),
            'warning' => $n->warning(),
            default => $n->info(),
        };
        $n->send();
    }
}
