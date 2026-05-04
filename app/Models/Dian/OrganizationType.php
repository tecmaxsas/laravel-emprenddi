<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

class OrganizationType extends Model
{
    protected $table = 'dian_organization_types';
    protected $fillable = ['code', 'name'];
}
