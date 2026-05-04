<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'dian_departments';

    protected $fillable = ['code', 'name'];

    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class, 'dian_department_id');
    }
}
