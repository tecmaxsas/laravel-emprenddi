<?php

namespace App\Models\Scopes;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentCompany::class);

        if (! $current->isSet()) {
            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $current->id());
    }
}
