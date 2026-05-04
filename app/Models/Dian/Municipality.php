<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Municipality extends Model
{
    protected $table = 'dian_municipalities';

    protected $fillable = ['dian_department_id', 'code', 'name', 'codefacturador'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'dian_department_id');
    }

    public function fullName(): string
    {
        return $this->department
            ? "{$this->name} ({$this->department->name})"
            : $this->name;
    }
}
