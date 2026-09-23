<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\Models\GlDetermination;
use Illuminate\Support\Facades\DB;

/**
 * Lee y escribe las cuentas de UN alcance concreto (un artículo, un grupo,
 * un almacén), para que cada ficha pueda administrar las suyas sin pasar por
 * la matriz central.
 *
 * ── Por qué la ficha y no solo la matriz ─────────────────────────────────
 *
 * La matriz central sirve para ver el panorama —qué reglas existen y en qué
 * nivel— pero es el lugar equivocado para el trabajo diario: dar de alta un
 * artículo y tener que ir a otra pantalla, elegirlo de una lista y repetirlo
 * por cada categoría es un recorrido que nadie hace, y el resultado es que
 * las determinaciones específicas no se configuran nunca. En la ficha están
 * al lado de los demás datos del artículo, que es donde uno las busca.
 *
 * Las dos escriben la MISMA tabla: no hay dos fuentes de verdad, hay dos
 * puertas a la misma.
 *
 * ── Dejar un campo vacío BORRA la regla ──────────────────────────────────
 *
 * Y borrarla significa "heredá del nivel de arriba", que es distinto de no
 * haberla tenido nunca solo en apariencia: el efecto es el mismo y por eso
 * vaciar el campo es la forma natural de decir "usá la del grupo".
 */
class GlDeterminationScopeService
{
    /**
     * Las categorías que se ofrecen en la ficha de cada entidad.
     *
     * No son las once de la matriz: en una ficha de artículo, "transitoria de
     * compras" o "desviación de fabricación" son ruido — se configuran una
     * vez a nivel de compañía y no cambian por artículo. Acá van las que de
     * verdad se diferencian por artículo, grupo o almacén.
     */
    public const CARD_CATEGORIES = [
        'item' => ['inventory', 'cogs', 'sales_revenue', 'service_revenue'],
        'item_group' => ['inventory', 'cogs', 'sales_revenue', 'service_revenue'],
        // Sin service_revenue: una línea de servicio no lleva almacén, así
        // que una regla de almacén para servicios no se aplicaría nunca.
        'warehouse' => ['inventory', 'cogs', 'sales_revenue'],
    ];

    /**
     * Las cuentas configuradas en ese alcance, como categoría => id de cuenta.
     *
     * @return array<string, int>
     */
    public function forScope(int $companyId, string $scopeLevel, ?int $scopeId): array
    {
        return GlDetermination::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('scope_level', $scopeLevel)
            ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'))
            ->when($scopeId !== null, fn ($q) => $q->where('scope_id', $scopeId))
            ->pluck('account_id', 'category')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Las cuentas de VARIOS alcances de un mismo nivel, para un listado.
     *
     * Una consulta y no una por fila: la pantalla de grupos o de almacenes
     * muestra todos a la vez, y con una consulta por entidad un catálogo de
     * cincuenta almacenes haría cincuenta.
     *
     * @return array<int, array<string, int>>  scope_id => (categoría => cuenta)
     */
    public function forScopeLevel(int $companyId, string $scopeLevel): array
    {
        return GlDetermination::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('scope_level', $scopeLevel)
            ->whereNotNull('scope_id')
            ->get(['scope_id', 'category', 'account_id'])
            ->groupBy('scope_id')
            ->map(fn ($rows) => $rows->pluck('account_id', 'category')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    /**
     * Guarda lo que llegó de la ficha: una cuenta crea o actualiza la regla,
     * un vacío la borra.
     *
     * @param  array<string, int|null>  $accounts  categoría => cuenta o null
     */
    public function sync(int $companyId, string $scopeLevel, ?int $scopeId, array $accounts): void
    {
        DB::transaction(function () use ($companyId, $scopeLevel, $scopeId, $accounts): void {
            foreach ($accounts as $category => $accountId) {
                // Solo las categorías que la ficha ofrece: si no, un payload
                // manipulado podría escribir reglas que esa pantalla no
                // muestra y que nadie encontraría después.
                if (! in_array($category, self::CARD_CATEGORIES[$scopeLevel] ?? [], true)) {
                    continue;
                }

                $query = GlDetermination::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $companyId)
                    ->where('scope_level', $scopeLevel)
                    ->where('category', $category)
                    ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'))
                    ->when($scopeId !== null, fn ($q) => $q->where('scope_id', $scopeId));

                if ($accountId === null || $accountId === '') {
                    $query->delete();

                    continue;
                }

                $existing = (clone $query)->first();

                if ($existing !== null) {
                    $existing->update(['account_id' => $accountId]);

                    continue;
                }

                GlDetermination::create([
                    'company_id' => $companyId,
                    'scope_level' => $scopeLevel,
                    'scope_id' => $scopeId,
                    'category' => $category,
                    'account_id' => $accountId,
                ]);
            }
        });
    }

    /**
     * Borra todas las reglas de un alcance. Lo llama la eliminación de un
     * artículo, grupo o almacén: dejar reglas huérfanas apuntando a un
     * scope_id que ya no existe las volvería invisibles en la matriz —
     * aparecen como "(eliminado)"— y no se podrían limpiar desde ningún lado.
     */
    public function forget(int $companyId, string $scopeLevel, int $scopeId): void
    {
        GlDetermination::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('scope_level', $scopeLevel)
            ->where('scope_id', $scopeId)
            ->delete();
    }
}
