<?php

namespace App\Models\Billiards;

use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un renglon de la cuenta de la mesa.
 *
 * El tiempo tambien es un renglon (kind=time). Podria ser una columna, pero
 * entonces una cuenta con dos tramos —jugaron en la 3, se pasaron a la 7—
 * no tendria donde mostrar cada tramo con su tarifa, que es justo lo que el
 * cliente pide ver cuando reclama.
 *
 * `unit_price` es precio AL PUBLICO (IVA incluido): el de la carta, el que
 * el cliente ve en el aviso. La base gravable se descompone al guardar, asi
 * que la factura copia base e impuesto tal cual y no hay dos calculos de
 * IVA que puedan discrepar en centavos.
 */
class BilliardCharge extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'billiard_session_charges';

    public const KIND_TIME = 'time';
    public const KIND_PRODUCT = 'product';
    public const KIND_SERVICE = 'service';
    public const KIND_ADJUSTMENT = 'adjustment';

    public const KINDS = [
        self::KIND_TIME => 'Tiempo de mesa',
        self::KIND_PRODUCT => 'Producto',
        self::KIND_SERVICE => 'Servicio',
        self::KIND_ADJUSTMENT => 'Ajuste',
    ];

    protected $fillable = [
        'company_id',
        'session_id',
        'kind',
        'product_id',
        'charge_date',
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
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BilliardSession::class, 'session_id');
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

    public function scopeConsumos(Builder $query): Builder
    {
        return $query->whereIn('kind', [self::KIND_PRODUCT, self::KIND_SERVICE]);
    }

    public function scopeTiempo(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_TIME);
    }

    public function esTiempo(): bool
    {
        return $this->kind === self::KIND_TIME;
    }

    /**
     * Si lo pone el sistema al liquidar.
     *
     * El tiempo no se quita a mano de la cuenta: lo decide el reloj y la
     * tarifa. Para perdonarlo esta el ajuste, que deja constancia de quien
     * regalo esa plata.
     */
    public function loPoneElSistema(): bool
    {
        return $this->kind === self::KIND_TIME;
    }

    public function etiquetaDelTipo(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
