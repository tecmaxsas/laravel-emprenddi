<?php

namespace App\Services\Commissions;

use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Support\CommissionsSettings;

/**
 * Motor de cálculo y causación de comisiones por vendedor.
 *
 * Flujo:
 *  - SaleInvoiceEngine llama causeForInvoice() cuando se cumple la
 *    condición de causación (al postear si 'invoiced', al quedar pagada
 *    si 'collected').
 *  - El engine calcula la comisión línea por línea resolviendo la tasa
 *    (product > category > all) y la base (subtotal/total/utilidad).
 *  - Crea una CommissionEntry 'pending' por (factura, vendedor).
 *  - Si la factura se anula/devuelve, reverseForInvoice() marca la
 *    entry como 'reversed' (si aún no fue liquidada).
 */
class CommissionEngine
{
    /**
     * Causa la comisión de una factura para su vendedor. Idempotente:
     * si ya existe una entry para (factura, vendedor) no crea otra.
     * Devuelve la entry creada (o la existente), o null si no aplica.
     */
    public function causeForInvoice(SaleInvoice $invoice, ?string $basis = null): ?CommissionEntry
    {
        if (! CommissionsSettings::moduleActive()) {
            return null;
        }

        $sellerId = $invoice->seller_user_id;
        if (! $sellerId) {
            return null; // sin vendedor asignado, no comisiona
        }

        // Idempotencia: si ya hay entry para esta factura+vendedor, no duplicar
        $existing = CommissionEntry::query()
            ->where('sale_invoice_id', $invoice->id)
            ->where('seller_user_id', $sellerId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $calc = $this->calculateForInvoice($invoice);
        if ($calc['amount'] <= 0) {
            return null; // el vendedor no tiene reglas aplicables o base 0
        }

        $basis ??= CommissionsSettings::causation();

        return CommissionEntry::create([
            'company_id' => $invoice->company_id,
            'seller_user_id' => $sellerId,
            'sale_invoice_id' => $invoice->id,
            'base_amount' => $calc['base_amount'],
            'amount' => $calc['amount'],
            'causation_basis' => $basis,
            'causation_date' => now(),
            'status' => CommissionEntry::STATUS_PENDING,
            'breakdown' => $calc['breakdown'],
        ]);
    }

    /**
     * Reversa la comisión de una factura (anulación/devolución total).
     * Solo afecta entries 'pending' — las ya liquidadas (settled) no se
     * tocan; en ese caso el ajuste debe hacerse en la siguiente liquidación.
     */
    public function reverseForInvoice(SaleInvoice $invoice): void
    {
        CommissionEntry::query()
            ->where('sale_invoice_id', $invoice->id)
            ->where('status', CommissionEntry::STATUS_PENDING)
            ->update([
                'status' => CommissionEntry::STATUS_REVERSED,
                'notes' => 'Reversada: factura anulada/devuelta el '.now()->format('Y-m-d H:i'),
            ]);
    }

    /**
     * Calcula la comisión de una factura sin persistir. Devuelve:
     *  ['base_amount' => float, 'amount' => float, 'breakdown' => array]
     * breakdown: lista de líneas con product_id, base, rate, amount.
     */
    public function calculateForInvoice(SaleInvoice $invoice): array
    {
        $invoice->loadMissing('lines');
        $sellerId = $invoice->seller_user_id;
        $base = CommissionsSettings::base();

        // Cargar e indexar las reglas del vendedor una sola vez
        $rules = CommissionRule::query()
            ->where('company_id', $invoice->company_id)
            ->where('seller_user_id', $sellerId)
            ->where('active', true)
            ->get();

        $ruleAll = $rules->firstWhere('scope', CommissionRule::SCOPE_ALL);
        $rulesByCategory = $rules->where('scope', CommissionRule::SCOPE_CATEGORY)->keyBy('category_id');
        $rulesByProduct = $rules->where('scope', CommissionRule::SCOPE_PRODUCT)->keyBy('product_id');

        // Pre-cargar categorias de los productos de la factura
        $productIds = $invoice->lines->pluck('product_id')->filter()->unique()->all();
        $categoryByProduct = Product::query()
            ->where('company_id', $invoice->company_id)
            ->whereIn('id', $productIds)
            ->pluck('category_id', 'id');

        $totalBase = 0.0;
        $totalAmount = 0.0;
        $breakdown = [];

        foreach ($invoice->lines as $line) {
            $productId = (int) $line->product_id;
            if ($productId <= 0) continue;

            // Resolver tasa: product > category > all
            $categoryId = $categoryByProduct[$productId] ?? null;
            $rate = $this->resolveRate($ruleAll, $rulesByCategory, $rulesByProduct, $productId, $categoryId);
            if ($rate <= 0) continue;

            // Resolver base de la linea segun setting
            $lineBase = $this->lineBase($line, $base);
            if ($lineBase <= 0) continue;

            $lineCommission = round($lineBase * ($rate / 100), 2);
            $totalBase += $lineBase;
            $totalAmount += $lineCommission;

            $breakdown[] = [
                'product_id' => $productId,
                'description' => $line->description,
                'base' => round($lineBase, 2),
                'rate' => $rate,
                'amount' => $lineCommission,
            ];
        }

        return [
            'base_amount' => round($totalBase, 2),
            'amount' => round($totalAmount, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Resuelve la tasa aplicable a un producto con prioridad:
     * regla de producto > regla de categoría > regla general (all).
     * Devuelve 0 si no hay ninguna.
     */
    private function resolveRate(
        ?CommissionRule $ruleAll,
        $rulesByCategory,
        $rulesByProduct,
        int $productId,
        ?int $categoryId,
    ): float {
        if ($rulesByProduct->has($productId)) {
            return (float) $rulesByProduct->get($productId)->rate;
        }
        if ($categoryId && $rulesByCategory->has($categoryId)) {
            return (float) $rulesByCategory->get($categoryId)->rate;
        }
        if ($ruleAll) {
            return (float) $ruleAll->rate;
        }
        return 0.0;
    }

    /**
     * Base de comisión de una línea según el setting de la empresa.
     */
    private function lineBase($line, string $base): float
    {
        $subtotalNet = (float) $line->subtotal - (float) $line->discount_amount;

        return match ($base) {
            CommissionsSettings::BASE_TOTAL => (float) $line->total,
            CommissionsSettings::BASE_PROFIT => max(
                0,
                $subtotalNet - ((float) $line->cost_at_sale * (float) $line->quantity),
            ),
            default => $subtotalNet, // BASE_SUBTOTAL
        };
    }
}
