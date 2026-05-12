<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'description',
        'active',
        'accepts_movements',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'accepts_movements' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function fullName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
