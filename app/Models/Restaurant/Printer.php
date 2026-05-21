<?php

namespace App\Models\Restaurant;

use App\Models\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Printer extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_printers';

    public const PURPOSES = [
        'kitchen' => 'Cocina',
        'bar' => 'Barra',
        'cashier' => 'Caja / Tickets',
        'other' => 'Otro',
    ];

    public const CONNECTION_TYPES = [
        'network' => 'Red (ESC/POS por TCP)',
        'cups' => 'CUPS (servidor de impresión)',
        'browser' => 'Navegador (QZ Tray local)',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'name',
        'purpose',
        'connection_type',
        'host',
        'port',
        'cups_queue',
        'printer_name',
        'category_ids',
        'columns',
        'open_cash_drawer',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'columns' => 'integer',
            'category_ids' => 'array',
            'open_cash_drawer' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * ¿Esta impresora maneja productos de esta categoría?
     *
     * category_ids viene del jsonb y sus elementos pueden ser strings
     * (["1","3"]) según cómo los guardó el form. Normalizamos a int en
     * ambos lados — sin esto, la comparación estricta 3 !== "3" hacía
     * que NINGÚN producto ruteara a una impresora.
     */
    public function handlesCategory(int $categoryId): bool
    {
        $ids = $this->category_ids ?? [];
        if (empty($ids)) return false;
        return in_array($categoryId, array_map('intval', $ids), true);
    }
}
