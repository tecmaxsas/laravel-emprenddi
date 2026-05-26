<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promocion automatica que se aplica al carrito segun condiciones de
 * vigencia, alcance (productos/categorias) y tipo.
 *
 * Tipos:
 *  - percentage:    descuento % sobre items del alcance
 *  - fixed_amount:  descuento fijo en COP sobre items del alcance
 *  - bogo:          buy X get Y (2x1, 3x2) — discount_data tiene buy_quantity/get_quantity
 *  - volume_tier:   escalonado por cantidad — discount_data tiene tiers[]
 *  - bundle:        combo a precio fijo — discount_data tiene items[] y bundle_price
 *
 * El motor de aplicacion (PromotionEngine, commit posterior) consulta
 * scopeApplicable() para filtrar candidatas, luego evalua cada una.
 */
class Promotion extends Model
{
    use HasFactory, BelongsToCompany;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_BOGO = 'bogo';
    public const TYPE_VOLUME_TIER = 'volume_tier';
    public const TYPE_BUNDLE = 'bundle';

    public const SCOPE_ALL = 'all';
    public const SCOPE_PRODUCTS = 'products';
    public const SCOPE_CATEGORIES = 'categories';

    protected $fillable = [
        'company_id', 'name', 'description', 'code', 'type', 'active',
        'discount_value', 'discount_data',
        'scope', 'scope_products', 'scope_categories',
        'requires_code', 'min_quantity', 'min_amount',
        'applies_dine_in', 'applies_takeaway', 'applies_delivery',
        'valid_from', 'valid_to', 'days_of_week', 'hour_from', 'hour_to',
        'max_uses_total', 'max_uses_per_customer', 'usage_count',
        'stackable', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'discount_value' => 'decimal:4',
            'discount_data' => 'array',
            'scope_products' => 'array',
            'scope_categories' => 'array',
            'requires_code' => 'boolean',
            'min_quantity' => 'integer',
            'min_amount' => 'decimal:2',
            'applies_dine_in' => 'boolean',
            'applies_takeaway' => 'boolean',
            'applies_delivery' => 'boolean',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'days_of_week' => 'array',
            'hour_from' => 'datetime:H:i',
            'hour_to' => 'datetime:H:i',
            'max_uses_total' => 'integer',
            'max_uses_per_customer' => 'integer',
            'usage_count' => 'integer',
            'stackable' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    // ====================================================================
    // Scopes
    // ====================================================================

    /** Solo promociones activas. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** Que esten dentro de la vigencia configurada (fechas + horario + dias). */
    public function scopeCurrentlyValid(Builder $q, ?Carbon $now = null): Builder
    {
        $now ??= now();
        return $q->where(function ($q) use ($now) {
            $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
        })->where(function ($q) use ($now) {
            $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
        });
    }

    /** Promociones automaticas (sin codigo de cupon). */
    public function scopeAutomatic(Builder $q): Builder
    {
        return $q->where('requires_code', false);
    }

    /** Solo cupones (requieren codigo). */
    public function scopeCoupons(Builder $q): Builder
    {
        return $q->where('requires_code', true)->whereNotNull('code');
    }

    // ====================================================================
    // Helpers de runtime
    // ====================================================================

    /**
     * Verifica si la promocion esta vigente ahora (fecha, dia, hora).
     * Considera valid_from/valid_to, days_of_week y hour_from/hour_to.
     */
    public function isCurrentlyValid(?Carbon $now = null): bool
    {
        $now ??= now();

        if ($this->valid_from && $now->lt($this->valid_from)) return false;
        if ($this->valid_to && $now->gt($this->valid_to)) return false;

        if (! empty($this->days_of_week)) {
            $dayKey = strtolower($now->englishDayOfWeek);
            // Aceptamos 'mon', 'monday' o numeros 0-6
            $shortDay = substr($dayKey, 0, 3);
            $allowed = array_map(
                fn ($d) => is_string($d) ? strtolower(substr($d, 0, 3)) : $d,
                $this->days_of_week,
            );
            if (! in_array($shortDay, $allowed, true) && ! in_array($now->dayOfWeek, $allowed, true)) {
                return false;
            }
        }

        if ($this->hour_from && $this->hour_to) {
            $minutes = $now->hour * 60 + $now->minute;
            $from = $this->hour_from->hour * 60 + $this->hour_from->minute;
            $to = $this->hour_to->hour * 60 + $this->hour_to->minute;
            if ($from <= $to) {
                if ($minutes < $from || $minutes > $to) return false;
            } else {
                // Rango cruza medianoche (ej. 22:00 → 02:00)
                if ($minutes < $from && $minutes > $to) return false;
            }
        }

        return true;
    }

    /** Si la promocion ya alcanzo su limite total de usos. */
    public function hasReachedTotalLimit(): bool
    {
        return $this->max_uses_total !== null
            && $this->usage_count >= $this->max_uses_total;
    }

    /** Usos por un cliente especifico (para validar max_uses_per_customer). */
    public function usagesByCustomer(?int $customerId): int
    {
        if (! $customerId) return 0;
        return $this->usages()
            ->where('customer_third_party_id', $customerId)
            ->count();
    }
}
