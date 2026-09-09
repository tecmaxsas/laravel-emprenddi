<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un catálogo público de productos.
 *
 * No guarda productos: guarda cómo se ven y cuáles entran. Los productos se
 * leen en vivo (ver App\Services\Catalog\CatalogProducts), así que un cambio de
 * precio o una foto nueva aparecen en el enlace sin tocar nada.
 */
class ProductCatalog extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * La paleta y la tipografía de arranque.
     *
     * Neutra a propósito: un catálogo que abre con los colores de otra marca
     * obliga a cambiarlos antes de poder mostrarlo. Estos se ven bien tal cual.
     */
    public const DEFAULT_THEME = [
        'primary_color' => '#0f172a',
        'accent_color' => '#2563eb',
        'bg_color' => '#f8fafc',
        'card_color' => '#ffffff',
        'text_color' => '#0f172a',
        'muted_color' => '#64748b',
        'font_family' => 'Inter',
        'header_style' => 'gradient',   // image | gradient | solid
        'layout' => 'grid',             // grid | list
        'card_shape' => 'rounded',      // rounded | square | soft
        'columns' => '3',               // 2 | 3 | 4  (en escritorio)
        'show_descriptions' => true,
        'show_images' => true,
    ];

    /** Fuentes de Google Fonts, descritas por cómo se ven y no por su nombre. */
    public const FONT_FAMILIES = [
        'Inter' => 'Inter (limpia, moderna)',
        'Poppins' => 'Poppins (redondeada, amable)',
        'Montserrat' => 'Montserrat (geométrica, comercial)',
        'Playfair Display' => 'Playfair Display (serif elegante)',
        'Lora' => 'Lora (serif suave, editorial)',
        'Cormorant Garamond' => 'Cormorant Garamond (serif clásica)',
        'Bebas Neue' => 'Bebas Neue (condensada, títulos fuertes)',
        'Quicksand' => 'Quicksand (redondeada, informal)',
        'Work Sans' => 'Work Sans (neutra, muy legible)',
        'Raleway' => 'Raleway (fina, sofisticada)',
    ];

    public const HEADER_STYLES = [
        'gradient' => 'Degradado de color',
        'solid' => 'Color sólido',
        'image' => 'Imagen de portada',
    ];

    public const LAYOUTS = [
        'grid' => 'Cuadrícula (tarjetas con foto)',
        'list' => 'Lista (una línea por producto)',
    ];

    public const CARD_SHAPES = [
        'rounded' => 'Esquinas redondeadas',
        'soft' => 'Esquinas suaves',
        'square' => 'Esquinas rectas',
    ];

    public const COLUMNS = [
        '2' => '2 por fila',
        '3' => '3 por fila',
        '4' => '4 por fila',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'subtitle',
        'logo_path',
        'header_image_path',
        'theme',
        'show_prices',
        'show_codes',
        'show_stock',
        'only_with_image',
        'category_ids',
        'whatsapp',
        'contact_phone',
        'contact_email',
        'footer_text',
        'allow_indexing',
        'active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'category_ids' => 'array',
            'show_prices' => 'boolean',
            'show_codes' => 'boolean',
            'show_stock' => 'boolean',
            'only_with_image' => 'boolean',
            'allow_indexing' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Un valor del tema, cayendo al de fábrica si nunca se tocó. */
    public function themeValue(string $clave, mixed $porDefecto = null): mixed
    {
        return data_get($this->theme, $clave)
            ?? $porDefecto
            ?? data_get(self::DEFAULT_THEME, $clave);
    }

    /** El tema completo, con los huecos rellenos. La vista lo usa sin guardas. */
    public function themeCompleto(): array
    {
        return array_merge(self::DEFAULT_THEME, array_filter(
            $this->theme ?? [],
            fn ($v) => $v !== null && $v !== '',
        ));
    }

    /** La URL que el usuario copia y comparte. */
    public function publicUrl(): string
    {
        return route('catalog.public', ['slug' => $this->slug]);
    }

    /** El logo propio, o el de la empresa si no cargaron uno. */
    public function logoParaMostrar(): ?string
    {
        return $this->logo_path ?: $this->company?->logo_path;
    }

    /**
     * El número de WhatsApp en el formato que espera wa.me: solo dígitos, con
     * indicativo. Si el usuario escribió «310 555 1234» se asume Colombia,
     * que es donde está el 100 % de los clientes de la plataforma.
     */
    public function whatsappNormalizado(): ?string
    {
        $numero = preg_replace('/\D+/', '', (string) ($this->whatsapp ?: $this->contact_phone));

        if (! $numero) {
            return null;
        }

        // 10 dígitos que empiezan por 3 = celular colombiano sin indicativo.
        if (strlen($numero) === 10 && str_starts_with($numero, '3')) {
            return '57'.$numero;
        }

        return $numero;
    }
}
