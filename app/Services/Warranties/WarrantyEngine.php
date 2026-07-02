<?php

namespace App\Services\Warranties;

use App\Models\Company;
use App\Models\ProductSerial;
use App\Models\SaleInvoice;
use App\Models\Warranty;
use App\Models\WarrantyEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lógica de negocio de tickets de garantía. Centraliza:
 *  - creación con cálculo de expiration_date desde products.warranty_days
 *    + evento "created"
 *  - transiciones de estado con validación + evento "status_change"
 *  - asignación de técnico + evento "assigned"
 *  - comentarios libres + evento "comment"
 *
 * Las transiciones permitidas viven en TRANSITIONS (matriz desde →
 * destinos válidos). Todo bloqueo de UI se justifica acá: si una
 * transición no aplica, lanza RuntimeException con mensaje claro.
 */
class WarrantyEngine
{
    public function __construct(
        protected WarrantyNumberer $numberer,
    ) {}

    /**
     * Transiciones permitidas. La pantalla de detalle filtra los botones
     * usando este mapa para no mostrar opciones imposibles.
     */
    public const TRANSITIONS = [
        Warranty::STATUS_RECEIVED => [Warranty::STATUS_IN_REVIEW, Warranty::STATUS_REJECTED],
        Warranty::STATUS_IN_REVIEW => [Warranty::STATUS_IN_REPAIR, Warranty::STATUS_REPLACED, Warranty::STATUS_REJECTED, Warranty::STATUS_RESOLVED],
        Warranty::STATUS_IN_REPAIR => [Warranty::STATUS_RESOLVED, Warranty::STATUS_REPLACED, Warranty::STATUS_REJECTED],
        Warranty::STATUS_RESOLVED => [Warranty::STATUS_DELIVERED],
        Warranty::STATUS_REPLACED => [Warranty::STATUS_DELIVERED],
        Warranty::STATUS_REJECTED => [Warranty::STATUS_DELIVERED],
        // delivered es terminal
        Warranty::STATUS_DELIVERED => [],
    ];

    /**
     * Crea un nuevo ticket. $data debe traer al menos:
     *   third_party_id, product_id, reason, claim_date
     *   location_id, product_serial_id?, sale_invoice_id?, rma_number?
     *
     * Si sale_invoice_id + product.warranty_days > 0, calcula
     * expiration_date = sale.date + warranty_days.
     */
    public function create(Company $company, array $data): Warranty
    {
        return DB::transaction(function () use ($company, $data) {
            $number = $this->numberer->next($company, $data['prefix'] ?? 'GAR');

            // Calcular fecha de vencimiento de garantía si tenemos
            // (a) producto con warranty_days y (b) fecha de venta.
            $expiration = $data['expiration_date'] ?? null;
            if (! $expiration && ! empty($data['product_id'])) {
                $product = \App\Models\Product::query()
                    ->where('company_id', $company->id)
                    ->find($data['product_id']);
                $warrantyDays = (int) ($product->warranty_days ?? 0);
                if ($warrantyDays > 0) {
                    $startDate = null;
                    if (! empty($data['sale_invoice_id'])) {
                        $startDate = SaleInvoice::query()
                            ->whereKey($data['sale_invoice_id'])
                            ->value('date');
                    }
                    if (! $startDate && ! empty($data['product_serial_id'])) {
                        $startDate = ProductSerial::query()
                            ->whereKey($data['product_serial_id'])
                            ->value('sold_at');
                    }
                    $startDate ??= $data['claim_date'];
                    $expiration = \Carbon\Carbon::parse($startDate)->addDays($warrantyDays)->toDateString();
                }
            }

            $warranty = Warranty::create([
                'company_id' => $company->id,
                'location_id' => $data['location_id'] ?? null,
                'prefix' => $data['prefix'] ?? 'GAR',
                'number' => $number,
                'rma_number' => $data['rma_number'] ?? null,
                'third_party_id' => $data['third_party_id'],
                'product_id' => $data['product_id'],
                'product_serial_id' => $data['product_serial_id'] ?? null,
                'sale_invoice_id' => $data['sale_invoice_id'] ?? null,
                'claim_date' => $data['claim_date'],
                'expiration_date' => $expiration,
                'status' => Warranty::STATUS_RECEIVED,
                'received_by_user_id' => Auth::id(),
                'reason' => $data['reason'],
            ]);

            $this->logEvent($warranty, WarrantyEvent::TYPE_CREATED, comment: 'Ticket creado');

            return $warranty;
        });
    }

    /**
     * Transiciona el estado. Valida que la transición esté en TRANSITIONS,
     * actualiza timestamps relevantes (resolved_at, delivered_at) y graba
     * el evento. $comment es opcional pero recomendado.
     */
    public function transitionTo(Warranty $warranty, string $toStatus, ?string $comment = null): Warranty
    {
        if ($warranty->isTerminal()) {
            throw new RuntimeException('La garantía ya fue entregada — no se puede cambiar de estado.');
        }
        $allowed = self::TRANSITIONS[$warranty->status] ?? [];
        if (! in_array($toStatus, $allowed, true)) {
            $human = Warranty::STATUSES[$toStatus] ?? $toStatus;
            $current = Warranty::STATUSES[$warranty->status] ?? $warranty->status;
            throw new RuntimeException("No puedes pasar de '{$current}' a '{$human}'.");
        }

        return DB::transaction(function () use ($warranty, $toStatus, $comment) {
            $from = $warranty->status;
            $patch = ['status' => $toStatus];

            // Timestamps automáticos
            if (in_array($toStatus, [Warranty::STATUS_RESOLVED, Warranty::STATUS_REPLACED, Warranty::STATUS_REJECTED], true)
                && $warranty->resolved_at === null) {
                $patch['resolved_at'] = now();
            }
            if ($toStatus === Warranty::STATUS_DELIVERED) {
                $patch['delivered_at'] = now();
            }

            // resolution_notes: si llegó comentario al pasar a un estado
            // resolutivo, lo guardamos también en el campo del modelo.
            if ($comment && in_array($toStatus, [Warranty::STATUS_RESOLVED, Warranty::STATUS_REPLACED, Warranty::STATUS_REJECTED], true)) {
                $patch['resolution_notes'] = trim(($warranty->resolution_notes ?? '')."\n".$comment);
            }

            $warranty->update($patch);

            $this->logEvent(
                $warranty,
                WarrantyEvent::TYPE_STATUS_CHANGE,
                fromStatus: $from,
                toStatus: $toStatus,
                comment: $comment,
            );

            return $warranty->fresh(['events']);
        });
    }

    public function assign(Warranty $warranty, ?int $userId, ?string $comment = null): Warranty
    {
        return DB::transaction(function () use ($warranty, $userId, $comment) {
            $previousId = $warranty->assigned_user_id;
            $warranty->update(['assigned_user_id' => $userId]);

            $payload = ['previous_user_id' => $previousId, 'new_user_id' => $userId];
            $this->logEvent(
                $warranty,
                WarrantyEvent::TYPE_ASSIGNED,
                comment: $comment,
                payload: $payload,
            );

            return $warranty->fresh(['events', 'assignedUser']);
        });
    }

    public function comment(Warranty $warranty, string $text): WarrantyEvent
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('El comentario no puede estar vacío.');
        }

        return $this->logEvent($warranty, WarrantyEvent::TYPE_COMMENT, comment: $text);
    }

    protected function logEvent(
        Warranty $warranty,
        string $type,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $comment = null,
        ?array $payload = null,
    ): WarrantyEvent {
        return WarrantyEvent::create([
            'warranty_id' => $warranty->id,
            'user_id' => Auth::id(),
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
