<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $table = 'dian_document_types';
    protected $fillable = ['code', 'name'];
}
