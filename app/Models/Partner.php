<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Socio / accionista de la empresa. El saldo de cuotas y el capital
 * aportado se derivan de los PartnerMovement; este modelo solo guarda
 * la identidad y la vinculación.
 */
class Partner extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const PERSON_TYPES = [
        'natural' => 'Persona Natural',
        'juridica' => 'Persona Jurídica',
    ];

    public const PARTNER_TYPES = [
        'socio' => 'Socio (cuotas)',
        'accionista' => 'Accionista (acciones)',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Activo',
        self::STATUS_WITHDRAWN => 'Retirado',
    ];

    protected $fillable = [
        'company_id',
        'person_type',
        'document_type',
        'document_number',
        'name',
        'email',
        'phone',
        'partner_type',
        'joined_at',
        'withdrawn_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'withdrawn_at' => 'date',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(PartnerMovement::class)->orderBy('date')->orderBy('id');
    }

    /**
     * Cuotas/acciones netas del socio = suma firmada de sus movimientos.
     * Usa la colección ya cargada si está disponible para evitar N+1.
     */
    public function totalQuotas(): float
    {
        $sum = $this->relationLoaded('movements')
            ? $this->movements->sum('quotas')
            : $this->movements()->sum('quotas');

        return round((float) $sum, 4);
    }

    /**
     * Capital neto aportado por el socio.
     */
    public function totalContributed(): float
    {
        $sum = $this->relationLoaded('movements')
            ? $this->movements->sum('amount')
            : $this->movements()->sum('amount');

        return round((float) $sum, 2);
    }

    /**
     * % de participación = cuotas del socio / cuotas totales de la empresa.
     */
    public function participationPercent(): float
    {
        $total = self::companyTotalQuotas();
        if ($total <= 0) {
            return 0;
        }

        return round($this->totalQuotas() / $total * 100, 4);
    }

    /**
     * Cuotas/acciones en circulación de toda la empresa — suma de todos
     * los movimientos de todos los socios. Memoizado por request.
     */
    public static function companyTotalQuotas(): float
    {
        static $cache = null;

        if ($cache === null) {
            $cache = (float) PartnerMovement::query()->sum('quotas');
        }

        return $cache;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
