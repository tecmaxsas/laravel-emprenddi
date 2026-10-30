<?php

namespace App\Models\Hotel;

use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un renglón del folio.
 *
 * Las noches también son renglones, una por noche. Podrían ser un solo total
 * «5 noches × $180.000», pero entonces el huésped que discute una noche no
 * tendría nada que señalar, y el hotel que le hace una cortesía no tendría
 * dónde aplicarla. Un folio se revisa renglón por renglón con el cliente al
 * frente.
 *
 * `unit_price` es precio AL PÚBLICO (IVA incluido), la misma convención que
 * la tarifa del tipo de habitación: la base gravable se descompone al
 * facturar. Si se guardara la base, el precio del renglón no coincidiría con
 * el del aviso de la recepción ni con la carta del restaurante.
 */
class StayCharge extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_stay_charges';

    public const KIND_ROOM = 'room';
    public const KIND_MEAL = 'meal';
    public const KIND_PRODUCT = 'product';
    public const KIND_SERVICE = 'service';
    public const KIND_ADJUSTMENT = 'adjustment';

    public const KINDS = [
        self::KIND_ROOM => 'Alojamiento',
        self::KIND_MEAL => 'Alimentación',
        self::KIND_PRODUCT => 'Producto',
        self::KIND_SERVICE => 'Servicio',
        self::KIND_ADJUSTMENT => 'Ajuste',
    ];

    protected $fillable = [
        'company_id',
        'stay_id',
        'kind',
        'product_id',
        'charge_date',
        'night_date',
        'description',
        'quantity',
        'unit_price',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'subtotal',
        'total',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'charge_date' => 'date',
            'night_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class, 'stay_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeNoches(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_ROOM);
    }

    public function scopeConsumos(Builder $query): Builder
    {
        return $query->whereNotIn('kind', [self::KIND_ROOM, self::KIND_MEAL]);
    }

    /** Los renglones que van atados a una noche: la habitación y el plan. */
    public function scopePorNoche(Builder $query): Builder
    {
        return $query->whereIn('kind', [self::KIND_ROOM, self::KIND_MEAL]);
    }

    public function esNoche(): bool
    {
        return $this->kind === self::KIND_ROOM;
    }

    /**
     * Si el renglón lo pone el sistema con cada noche.
     *
     * La habitación y el plan de alimentación no se quitan a mano del folio:
     * los decide la liquidación según las noches que de verdad se durmieron.
     */
    public function loPoneElSistema(): bool
    {
        return in_array($this->kind, [self::KIND_ROOM, self::KIND_MEAL], true);
    }

    public function etiquetaDelTipo(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
