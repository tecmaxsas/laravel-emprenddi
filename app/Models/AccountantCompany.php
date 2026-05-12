<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AccountantCompany extends Pivot
{
    use HasFactory;

    protected $table = 'accountant_companies';

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'company_id',
        'active',
        'granted_at',
        'granted_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'granted_at' => 'datetime',
        ];
    }

    public function accountant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
