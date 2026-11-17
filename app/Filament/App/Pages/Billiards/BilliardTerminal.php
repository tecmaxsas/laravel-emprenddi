<?php

namespace App\Filament\App\Pages\Billiards;

use App\Filament\Concerns\AbreYCierraCaja;
use App\Filament\Concerns\EligeCliente;
use App\Models\Account;
use App\Models\Billiards\BilliardCharge;
use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardSession;
use App\Models\Billiards\BilliardTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ThirdParty;
use App\Services\Billiards\BilliardAccount;
use App\Services\Billiards\BilliardBillingEngine;
use App\Services\Billiards\BilliardProductProvisioner;
use App\Services\Billiards\BilliardRateEngine;
use App\Services\Billiards\BilliardSessionEngine;
use App\Support\ModuleGate;
use App\Support\PaymentAccountResolver;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * El mapa del salón: una sola pantalla para operar el billar.
 *
 * Cada mesa es un tile. Verde libre, rojo ocupada, ámbar en pausa. Un clic
 * en una libre la abre; un clic en una ocupada abre su cuenta, que es donde
 * se cargan las cervezas, se para el reloj, se traslada la mesa y se cobra.
 *
 * El reloj se pinta en el navegador y no en el servidor: una sala de doce
 * mesas repintando cada segundo desde Livewire son doce consultas por
 * segundo para mover un número. El servidor manda la hora de arranque y los
 * minutos ya pausados; el contador lo lleva el cliente, y el total de verdad
 * se recalcula cuando el cajero abre el cobro.
 *
 * El estado de las modales se controla con $mode:
 *   idle       sin modal
 *   open       abriendo una mesa
 *   account    la cuenta de una mesa ocupada
 *   close      el cobro
 *   transfer   eligiendo a qué mesa se pasan
 */
class BilliardTerminal extends Page
{
    use AbreYCierraCaja;
    use EligeCliente;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Terminal';

    protected static ?string $navigationGroup = 'Billares';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'billar';

    protected static ?string $title = 'Terminal de Billares';

    protected static string $view = 'filament.app.pages.billiards.terminal';

    public ?int $hallId = null;

    /** idle | open | account | close | transfer */
    public string $mode = 'idle';

    public ?int $selectedTableId = null;

    public ?int $activeSessionId = null;

    public array $openForm = [];

    public array $cobroForm = [];

    public string $consumoSearch = '';

    public string $ajusteConcepto = '';

    public ?float $ajusteMonto = null;

    public string $pauseReason = '';

    public ?int $transferToTableId = null;

    public string $cancelReason = '';

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('billiards')) {
            return false;
        }

        return (bool) auth()->user()?->can('billiards.use');
    }

    public function mount(): void
    {
        $this->hallId = BilliardHall::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('id')
            ->value('id');

        $this->resetOpenForm();
        $this->resetCobroForm();
    }

    /* ------------------------------------------------------------------ */
    /*  Lo que pinta la vista                                              */
    /* ------------------------------------------------------------------ */

    public function getHallsProperty(): Collection
    {
        return BilliardHall::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    public function getCurrentHallProperty(): ?BilliardHall
    {
        return $this->hallId
            ? BilliardHall::query()
                ->where('company_id', auth()->user()?->company_id)
                ->find($this->hallId)
            : null;
    }

    /**
     * Las mesas agrupadas por zona, con su cuenta abierta ya cargada.
     *
     * El eager load de `openSessions.pauses` no es un lujo: sin él, cada
     * tile que calcula su reloj dispara una consulta por las pausas, y el
     * mapa se repinta solo.
     *
     * @return array<string, list<BilliardTable>>
     */
    public function getTablesByZoneProperty(): array
    {
        $sala = $this->currentHall;

        if (! $sala) {
            return [];
        }

        return app(BilliardSessionEngine::class)
            ->mapaDeLaSala($sala)
            ->groupBy(fn (BilliardTable $m) => $m->zone ?: 'Salón')
            ->map(fn ($mesas) => $mesas->values()->all())
            ->all();
    }

    public function getStatsProperty(): array
    {
        $mesas = collect($this->tablesByZone)->flatten(1);

        return [
            'free' => $mesas->where('status', BilliardTable::STATUS_FREE)->count(),
            'occupied' => $mesas->where('status', BilliardTable::STATUS_OCCUPIED)->count(),
            'total' => $mesas->count(),
        ];
    }

    public function getActiveSessionProperty(): ?BilliardSession
    {
        if (! $this->activeSessionId) {
            return null;
        }

        return BilliardSession::query()
            ->where('company_id', auth()->user()?->company_id)
            ->with(['table', 'tableType', 'rate', 'pauses', 'charges', 'customer'])
            ->find($this->activeSessionId);
    }

    public function getSelectedTableProperty(): ?BilliardTable
    {
        return $this->selectedTableId
            ? BilliardTable::query()
                ->where('company_id', auth()->user()?->company_id)
                ->with('tableType')
                ->find($this->selectedTableId)
            : null;
    }

    /** Lo que va costando el tiempo ahora mismo, sin escribir nada. */
    public function getQuoteProperty(): ?array
    {
        $sesion = $this->activeSession;

        return $sesion ? app(BilliardSessionEngine::class)->cotizar($sesion) : null;
    }

    /** Lo que se le cobra al cliente: lo que ya está escrito más el tiempo corriendo. */
    public function getGrandTotalProperty(): float
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return 0.0;
        }

        return $sesion->totalProyectado((float) ($this->quote['amount'] ?? 0));
    }

    public function getChargesProperty(): Collection
    {
        $sesion = $this->activeSession;

        return $sesion
            ? $sesion->charges()->orderBy('id')->get()
            : collect();
    }

    /** Las mesas libres de la sala, para el traslado. */
    public function getFreeTablesProperty(): Collection
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return collect();
        }

        return BilliardTable::query()
            ->with('tableType')
            ->where('hall_id', $sesion->hall_id)
            ->where('active', true)
            ->where('status', BilliardTable::STATUS_FREE)
            ->orderBy('zone')->orderBy('sort_order')->orderBy('code')
            ->get();
    }

    public function getPaymentMethodOptionsProperty(): array
    {
        $configurados = PaymentMethod::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'code')->all();

        return $configurados !== [] ? $configurados : [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
        ];
    }

    public function getAccountOptionsProperty(): array
    {
        return Account::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where(fn ($q) => $q->where('code', 'like', '11%')->orWhere('code', 'like', '12%'))
            ->orderBy('code')->limit(50)->get()
            ->mapWithKeys(fn ($a) => [$a->id => "{$a->code} — {$a->name}"])
            ->all();
    }

    /**
     * El buscador de consumos.
     *
     * Deja fuera el producto de alquiler de mesa: cargarlo a mano como si
     * fuera una cerveza duplicaría el tiempo que el reloj ya cobra.
     */
    public function getProductSearchResultsProperty(): Collection
    {
        $q = trim($this->consumoSearch);

        if (mb_strlen($q) < 2) {
            return collect();
        }

        $companyId = (int) (auth()->user()?->company_id ?? 0);

        if (! $companyId) {
            return collect();
        }

        return Product::query()
            ->where('company_id', $companyId)
            ->where('is_sellable', true)
            ->where('active', true)
            ->where('code', '!=', BilliardProductProvisioner::CODE)
            ->where(fn ($b) => $b->where('name', 'ilike', "%{$q}%")
                ->orWhere('code', 'ilike', "%{$q}%")
                ->orWhere('barcode', 'ilike', "%{$q}%"))
            ->orderBy('name')
            ->limit(12)
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /*  Navegación del mapa                                                */
    /* ------------------------------------------------------------------ */

    public function changeHall(int $hallId): void
    {
        $this->hallId = $hallId;
        $this->closeModal();
    }

    public function selectTable(int $tableId): void
    {
        $mesa = BilliardTable::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($tableId);

        if (! $mesa) {
            return;
        }

        $this->selectedTableId = $mesa->id;

        if ($mesa->status === BilliardTable::STATUS_OCCUPIED) {
            $abierta = BilliardSession::query()
                ->where('table_id', $mesa->id)
                ->where('status', BilliardSession::STATUS_OPEN)
                ->latest('started_at')
                ->first();

            if (! $abierta) {
                // La mesa quedó marcada ocupada sin cuenta: pasa si alguien
                // tocó la base a mano. Se libera en vez de dejar un tile
                // muerto que nadie puede usar.
                $mesa->update(['status' => BilliardTable::STATUS_FREE]);
                $this->notificar('warning', 'Esa mesa estaba marcada ocupada sin cuenta abierta. Quedó libre.');

                return;
            }

            $this->activeSessionId = $abierta->id;
            $this->mode = 'account';

            return;
        }

        if ($mesa->status !== BilliardTable::STATUS_FREE) {
            $this->notificar('warning', 'Esa mesa está en '.mb_strtolower(BilliardTable::STATUSES[$mesa->status] ?? $mesa->status).'.');

            return;
        }

        $this->resetOpenForm();
        $this->mode = 'open';
    }

    public function closeModal(): void
    {
        $this->mode = 'idle';
        $this->selectedTableId = null;
        $this->activeSessionId = null;
        $this->consumoSearch = '';
        $this->ajusteConcepto = '';
        $this->ajusteMonto = null;
        $this->pauseReason = '';
        $this->transferToTableId = null;
        $this->cancelReason = '';
    }

    /* ------------------------------------------------------------------ */
    /*  Operación de la mesa                                               */
    /* ------------------------------------------------------------------ */

    public function abrirMesa(): void
    {
        if (! $this->selectedTableId) {
            return;
        }

        try {
            $sesion = app(BilliardSessionEngine::class)->abrir([
                'table_id' => $this->selectedTableId,
                'players' => (int) ($this->openForm['players'] ?? 2),
                'customer_label' => $this->openForm['customer_label'] ?? null,
                'notes' => $this->openForm['notes'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo abrir la mesa', $e->getMessage());

            return;
        }

        $this->activeSessionId = $sesion->id;
        $this->mode = 'account';

        $this->notificar('success', ($sesion->table?->etiqueta() ?? 'Mesa').' abierta',
            'El reloj está corriendo.');
    }

    public function pausar(): void
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return;
        }

        try {
            app(BilliardSessionEngine::class)->pausar($sesion, $this->pauseReason);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo pausar', $e->getMessage());

            return;
        }

        $this->pauseReason = '';
        $this->notificar('success', 'Reloj detenido', 'La mesa sigue ocupada.');
    }

    public function reanudar(): void
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return;
        }

        try {
            app(BilliardSessionEngine::class)->reanudar($sesion);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo reanudar', $e->getMessage());

            return;
        }

        $this->notificar('success', 'Reloj corriendo otra vez');
    }

    public function agregarConsumo(int $productId): void
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return;
        }

        $producto = Product::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($productId);

        if (! $producto) {
            $this->notificar('danger', 'Ese producto ya no existe');

            return;
        }

        try {
            app(BilliardAccount::class)->agregarConsumo($sesion, [
                'product_id' => $producto->id,
                'description' => $producto->name,
                'quantity' => 1,
                'unit_price' => (float) $producto->default_sale_price,
                'tax_id' => $producto->default_sale_tax_id,
            ]);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo cargar el consumo', $e->getMessage());

            return;
        }

        $this->consumoSearch = '';
        $this->notificar('success', $producto->name.' cargado a la mesa');
    }

    /** El botón «otra» de un renglón ya cargado: cobra una unidad más. */
    public function repetirConsumo(int $chargeId): void
    {
        $cargo = $this->cargoDeLaCuenta($chargeId);

        if (! $cargo || $cargo->loPoneElSistema()) {
            return;
        }

        try {
            app(BilliardAccount::class)->agregarConsumo($cargo->session, [
                'product_id' => $cargo->product_id,
                'description' => $cargo->description,
                'quantity' => 1,
                'unit_price' => (float) $cargo->unit_price,
                'tax_id' => $cargo->tax_id,
                'kind' => $cargo->kind,
            ]);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo repetir', $e->getMessage());
        }
    }

    public function quitarCargo(int $chargeId): void
    {
        $cargo = $this->cargoDeLaCuenta($chargeId);

        if (! $cargo) {
            return;
        }

        try {
            app(BilliardAccount::class)->quitar($cargo);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo quitar', $e->getMessage());

            return;
        }

        $this->notificar('success', 'Renglón quitado de la cuenta');
    }

    /**
     * La cortesía de la casa. Exige `billiards.adjust`: rebajar una cuenta
     * es plata que el salón deja de recibir, y eso no lo decide quien está
     * atendiendo la mesa.
     */
    public function agregarAjuste(): void
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return;
        }

        if (! auth()->user()?->can('billiards.adjust')) {
            $this->notificar('danger', 'Sin permiso para aplicar cortesías',
                'Pídele a un administrador que lo autorice.');

            return;
        }

        try {
            app(BilliardAccount::class)->agregarAjuste(
                $sesion,
                $this->ajusteConcepto,
                (float) ($this->ajusteMonto ?? 0),
            );
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo aplicar el ajuste', $e->getMessage());

            return;
        }

        $this->ajusteConcepto = '';
        $this->ajusteMonto = null;
        $this->notificar('success', 'Ajuste aplicado a la cuenta');
    }

    public function abrirTraslado(): void
    {
        $this->transferToTableId = null;
        $this->mode = 'transfer';
    }

    public function trasladar(): void
    {
        $sesion = $this->activeSession;

        if (! $sesion || ! $this->transferToTableId) {
            $this->notificar('warning', 'Elige la mesa de destino');

            return;
        }

        $destino = BilliardTable::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($this->transferToTableId);

        if (! $destino) {
            $this->notificar('danger', 'Esa mesa ya no existe');

            return;
        }

        try {
            app(BilliardSessionEngine::class)->trasladar($sesion, $destino);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo trasladar', $e->getMessage());

            return;
        }

        $this->transferToTableId = null;
        $this->mode = 'account';
        $this->notificar('success', 'Cuenta trasladada a '.$destino->etiqueta(),
            'El tiempo jugado hasta ahora quedó cobrado a la tarifa de la mesa anterior.');
    }

    public function anular(): void
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return;
        }

        if (! auth()->user()?->can('billiards.cancel')) {
            $this->notificar('danger', 'Sin permiso para anular cuentas');

            return;
        }

        try {
            app(BilliardSessionEngine::class)->anular($sesion, $this->cancelReason);
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo anular', $e->getMessage());

            return;
        }

        $this->closeModal();
        $this->notificar('success', 'Cuenta anulada');
    }

    /* ------------------------------------------------------------------ */
    /*  Cobro                                                              */
    /* ------------------------------------------------------------------ */

    public function abrirCobro(): void
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return;
        }

        $this->resetCobroForm();

        $this->cobroForm['invoice_kind'] = $sesion->hall?->default_invoice_kind ?? 'pos';
        $this->cobroForm['games'] = (int) $sesion->games;

        if ($sesion->third_party_id) {
            $this->cobroForm['third_party_id'] = $sesion->third_party_id;
            $this->cobroForm['third_party_label'] = $sesion->customer?->name;
        }

        $this->mode = 'close';
    }

    /**
     * Cierra la mesa y emite la factura, en ese orden.
     *
     * Son dos pasos y no uno porque pueden fallar por separado: si el cobro
     * falla —no hay turno de caja, falta la resolución DIAN— la mesa ya
     * quedó libre y la cuenta cerrada con su tiempo liquidado, y se puede
     * facturar después desde el histórico. Al revés, un error de facturación
     * dejaría la mesa ocupada con los clientes ya en la puerta.
     */
    public function cobrar(): void
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return;
        }

        $manual = $this->cobroForm['amount_override'] ?? null;
        $manual = ($manual === '' || $manual === null) ? null : (float) $manual;

        try {
            $cerrada = app(BilliardSessionEngine::class)->cerrar(
                $sesion,
                null,
                $manual,
                isset($this->cobroForm['games']) ? (int) $this->cobroForm['games'] : null,
            );
        } catch (\Throwable $e) {
            $this->notificar('danger', 'No se pudo cerrar la mesa', $e->getMessage());

            return;
        }

        if ((float) $cerrada->total <= 0) {
            $this->closeModal();
            $this->notificar('success', 'Mesa cerrada sin cobro',
                'La cuenta quedó en cero, así que no se emitió factura.');

            return;
        }

        try {
            $factura = app(BilliardBillingEngine::class)->issueForSession($cerrada, [
                'invoice_kind' => $this->cobroForm['invoice_kind'] ?? 'pos',
                'payment_method' => $this->cobroForm['payment_method'] ?? 'cash',
                'account_id' => (int) ($this->cobroForm['account_id'] ?? 0),
                'third_party_id' => $this->cobroForm['third_party_id'] ?? null,
                'cash_received' => $this->cobroForm['cash_received'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->closeModal();
            $this->notificar('danger', 'La mesa se cerró pero NO se facturó', $e->getMessage()
                .' La cuenta quedó cerrada con su tiempo liquidado: se puede facturar desde el histórico.');

            return;
        }

        $this->closeModal();
        $this->notificar('success', 'Cobrado · '.$factura->fullNumber(),
            'Total $'.number_format((float) $factura->total, 0, ',', '.'));
    }

    /* ------------------------------------------------------------------ */

    protected function aplicarCliente(?ThirdParty $cliente): void
    {
        $this->cobroForm['third_party_id'] = $cliente?->id;
        $this->cobroForm['third_party_label'] = $cliente?->name;
    }

    /** El método de pago decide la cuenta contable; el cajero puede cambiarla. */
    public function updatedCobroForm($value, $key): void
    {
        if ($key !== 'payment_method') {
            return;
        }

        $this->cobroForm['account_id'] = PaymentAccountResolver::forMethod(
            $value,
            (int) auth()->user()?->company_id,
        );
    }

    protected function cargoDeLaCuenta(int $chargeId): ?BilliardCharge
    {
        $sesion = $this->activeSession;

        if (! $sesion) {
            return null;
        }

        return BilliardCharge::query()
            ->where('session_id', $sesion->id)
            ->find($chargeId);
    }

    protected function resetOpenForm(): void
    {
        $this->openForm = [
            'players' => 2,
            'customer_label' => '',
            'notes' => null,
        ];
    }

    protected function resetCobroForm(): void
    {
        $metodo = 'cash';

        $this->cobroForm = [
            'payment_method' => $metodo,
            'account_id' => PaymentAccountResolver::forMethod($metodo, (int) auth()->user()?->company_id),
            'invoice_kind' => 'pos',
            'third_party_id' => null,
            'third_party_label' => null,
            'cash_received' => null,
            'amount_override' => null,
            'games' => 0,
        ];
    }

    protected function notificar(string $tipo, string $titulo, ?string $cuerpo = null): void
    {
        $n = Notification::make()->title($titulo);

        if ($cuerpo) {
            $n->body($cuerpo);
        }

        match ($tipo) {
            'success' => $n->success(),
            'warning' => $n->warning(),
            'danger' => $n->danger()->persistent(),
            default => $n->info(),
        };

        $n->send();
    }

    /**
     * Lo que necesita un tile para pintarse: la cuenta abierta, el reloj y
     * lo que va debiendo.
     *
     * Sale de aquí y no de la vista porque la proyección del total —lo
     * escrito más el tiempo corriendo— es una regla de negocio, y repetida
     * en un blade se queda vieja cuando la regla cambie.
     *
     * No pega a la base: las mesas llegan con su cuenta, sus pausas y su
     * tarifa ya cargadas desde `mapaDeLaSala()`.
     *
     * @return array{session: BilliardSession, minutes: int, running: bool, total: float}|null
     */
    public function resumenDeMesa(BilliardTable $mesa): ?array
    {
        $sesion = $mesa->openSessions->first();

        if (! $sesion) {
            return null;
        }

        $cotizacion = app(BilliardSessionEngine::class)->cotizar($sesion);

        return [
            'session' => $sesion,
            'minutes' => $sesion->minutosJugados(),
            'running' => ! $sesion->estaPausada(),
            'total' => $sesion->totalProyectado((float) $cotizacion['amount']),
        ];
    }

    /** Para la vista: «1 h 25 min». */
    public function enHoras(int $minutos): string
    {
        return app(BilliardRateEngine::class)->enHoras($minutos);
    }
}
