<?php

namespace App\Domains\Core\Scopes;

use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Para catálogos con dos niveles a la vez: filas globales (company_id NULL,
 * compartidas por todo el sistema — ver TaxType/TaxRate) y filas propias de
 * UNA compañía (company_id = esa compañía, invisibles para las demás).
 *
 * A diferencia de CompanyScope (que aísla por completo sin compañía activa),
 * acá "sin compañía activa" no puede significar "nada visible": el panel del
 * Propietario (sin CurrentCompany, ver docs/decisiones.md sobre los dos
 * planos) necesita seguir administrando el catálogo global — por eso el caso
 * sin compañía activa muestra únicamente lo global (nunca lo propio de
 * ninguna compañía), en vez de fallar cerrado a cero filas.
 */
class GlobalOrOwnCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('company_id');
        $currentCompany = app(CurrentCompany::class);

        if ($currentCompany->isSet()) {
            $builder->where(function (Builder $query) use ($column, $currentCompany) {
                $query->whereNull($column)->orWhere($column, $currentCompany->id());
            });

            return;
        }

        $builder->whereNull($column);
    }
}
