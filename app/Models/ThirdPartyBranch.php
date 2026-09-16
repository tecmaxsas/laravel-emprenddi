<?php

namespace App\Models;

use App\Models\OrderTaking\PriceList;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una sucursal de un cliente: dirección de entrega con datos comerciales
 * propios, colgando del único tercero que la DIAN conoce.
 *
 * **No es un cliente.** La factura se emite siempre al NIT del tercero; la
 * sucursal dice a dónde se entrega y con qué condiciones se vende. Tratarla como
 * cliente aparte —duplicando el tercero con el documento modificado— parte la
 * cartera, rompe el cupo de crédito y hace que el estado de cuenta no cuadre con
 * lo que el cliente cree deber.
 *
 * **Nulo no es cero.** La lista de precios, el vendedor y el cupo van nulos
 * cuando la sucursal no tiene los suyos, y eso significa «lo que diga el
 * tercero». Un cupo en cero, en cambio, es «esta sucursal no compra a crédito».
 */
class ThirdPartyBranch extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $table = 'third_party_branches';

    protected $fillable = [
        'company_id',
        'third_party_id',
        'code',
        'name',
        'address',
        'city',
        'department',
        'dian_municipality_id',
        'contact_person',
        'contact_phone',
        'phone',
        'email',
        'delivery_horario',
        'default_price_list_id',
        'default_seller_user_id',
        'credit_limit',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'dian_municipality_id' => 'integer',
            // Nullable a propósito: null es «hereda del tercero».
            'credit_limit' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'default_price_list_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_seller_user_id');
    }

    /** Cómo se nombra en un selector: el código es por lo que preguntan. */
    public function label(): string
    {
        return $this->code
            ? "{$this->code} — {$this->name}"
            : $this->name;
    }

    /**
     * La lista de precios que aplica, propia o heredada.
     *
     * Resolver la herencia aquí y no en cada pantalla es lo que evita que el
     * pedido y la factura terminen usando listas distintas para la misma venta.
     */
    public function priceListId(): ?int
    {
        return $this->default_price_list_id
            ?? $this->thirdParty?->default_price_list_id;
    }

    public function sellerUserId(): ?int
    {
        return $this->default_seller_user_id
            ?? $this->thirdParty?->default_seller_user_id;
    }

    /** El cupo propio, o null si comparte el del NIT. */
    public function creditLimit(): ?float
    {
        return $this->credit_limit === null
            ? null
            : (float) $this->credit_limit;
    }

    /** La dirección de entrega, en una línea. */
    public function fullAddress(): string
    {
        return collect([$this->address, $this->city, $this->department])
            ->filter()
            ->implode(', ');
    }
}
