<?php

namespace App\Domains\Core\Scopes;

use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $currentCompany = app(CurrentCompany::class);

        if ($currentCompany->isSet()) {
            $builder->where($model->qualifyColumn('company_id'), $currentCompany->id());

            return;
        }

        // Fail closed: sin compañía activa no se expone ninguna fila. El super
        // usuario que necesite cruzar compañías debe usar withoutGlobalScope()
        // explícitamente (CLAUDE.md: "toda query pasa por el scope de company activa").
        $builder->whereRaw('1 = 0');
    }
}
