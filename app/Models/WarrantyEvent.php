<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada en la bitácora de una garantía. Los timestamps usan
 * `created_at` (DB tiene useCurrent()) y `updated_at` no aplica:
 * los eventos son inmutables — si necesitas corregir un comentario,
 * crea uno nuevo.
 */
class WarrantyEvent extends Model
{
    use HasFactory;

    public const TYPE_CREATED = 'created';

    public const TYPE_STATUS_CHANGE = 'status_change';

    public const TYPE_COMMENT = 'comment';

    public const TYPE_ASSIGNED = 'assigned';

    public const TYPE_ATTACHMENT = 'attachment';

    // No usamos updated_at — los eventos son append-only.
    public $timestamps = false;

    protected $fillable = [
        'warranty_id',
        'user_id',
        'event_type',
        'from_status',
        'to_status',
        'comment',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'payload' => 'array',
    ];

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
