<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceTemplate extends Model
{
    use BelongsToCompany;

    public const PAPER_SIZES = [
        'pos_58' => 'POS Tirilla 58mm',
        'pos_80' => 'POS Tirilla 80mm',
        'letter_half' => 'Media carta',
        'letter' => 'Carta',
        'a5' => 'A5',
        'a4' => 'A4',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'paper_size',
        'settings',
        'footer_text',
        'is_default',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Estructura por defecto del JSON `settings`. Sirve como base para el form
     * de creación y como fallback al renderizar el ticket si una key no existe.
     */
    public static function defaultSettings(): array
    {
        return [
            'header' => [
                'show_logo' => true,
                'show_business_name' => true,
                'show_legal_name' => true,
                'show_nit' => true,
                'show_address' => true,
                'show_phone' => true,
                'show_email' => false,
                'show_location_name' => true,
                'show_dian_resolution' => true,
            ],
            'customer' => [
                'show' => true,
                'show_name' => true,
                'show_document' => true,
                'show_address' => false,
                'show_phone' => false,
                'show_email' => false,
            ],
            'lines' => [
                'show_code' => true,
                'show_barcode' => false,
                'show_description' => true,
                'show_quantity' => true,
                'show_unit_price' => true,
                'show_discount' => true,
                'show_tax' => false,
                'show_total' => true,
            ],
            'totals' => [
                'show_subtotal' => true,
                'show_discount' => true,
                'show_tax_breakdown' => true,
                'show_total' => true,
                'show_paid' => true,
                'show_change' => true,
            ],
            'footer' => [
                'show_qr_dian' => true,
                'show_cufe' => true,
                'show_resolution_info' => true,
                'show_seller' => true,
                'show_thanks' => true,
            ],
        ];
    }

    /**
     * Devuelve el setting profundo con fallback al default.
     * Uso: $template->setting('header.show_logo') → bool.
     */
    public function setting(string $path, mixed $default = null): mixed
    {
        $value = data_get($this->settings, $path);
        if ($value !== null) {
            return $value;
        }

        return data_get(self::defaultSettings(), $path, $default);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function paperSizeLabel(): string
    {
        return self::PAPER_SIZES[$this->paper_size] ?? $this->paper_size;
    }

    public function isThermal(): bool
    {
        return in_array($this->paper_size, ['pos_58', 'pos_80'], true);
    }
}
