<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un enlace público para que un cliente vea su estado de cuenta.
 *
 * Ver la migración para el porqué del token largo y la caducidad.
 */
class CustomerStatementShare extends Model
{
    use BelongsToCompany;

    /** Cuántos días vive un enlace si no se dice otra cosa. */
    public const DIAS_POR_DEFECTO = 30;

    protected $fillable = [
        'company_id',
        'third_party_id',
        'token',
        'from_date',
        'to_date',
        'sent_to',
        'expires_at',
        'last_viewed_at',
        'view_count',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Crea el enlace para un cliente.
     *
     * Cada vez que se comparte se genera uno nuevo en vez de reutilizar el
     * anterior: así el reloj de la caducidad arranca de cero y se puede saber
     * a qué número se mandó cada uno.
     */
    public static function paraCliente(
        ThirdParty $customer,
        ?string $desde = null,
        ?string $hasta = null,
        ?string $telefono = null,
        int $dias = self::DIAS_POR_DEFECTO,
    ): self {
        return self::create([
            'company_id' => $customer->company_id,
            'third_party_id' => $customer->id,
            'token' => Str::random(40),
            'from_date' => $desde ?: null,
            'to_date' => $hasta ?: null,
            'sent_to' => $telefono,
            'expires_at' => now()->addDays(max(1, $dias)),
            // Explícito aunque la columna tenga default: así el objeto recién
            // creado ya sabe su contador y no devuelve null.
            'view_count' => 0,
            'created_by_user_id' => auth()->id(),
        ]);
    }

    public function vigente(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function publicUrl(): string
    {
        return route('customer-statement.public', ['token' => $this->token]);
    }

    /** Deja constancia de que alguien lo abrió. */
    public function registrarVisita(): void
    {
        $this->forceFill([
            'last_viewed_at' => now(),
            'view_count' => $this->view_count + 1,
        ])->saveQuietly();
    }
}
