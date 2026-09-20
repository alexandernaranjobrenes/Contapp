<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\Exceptions\MissingGlDeterminationException;
use App\Domains\Inventory\Models\GlDetermination;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * Resuelve qué cuenta contable usar para una categoría, aplicando la
 * precedencia de SAP B1: del alcance más específico al más general
 * (artículo > grupo > almacén > compañía) y, si la matriz no resuelve nada,
 * la cuenta por defecto del tipo de documento como último recurso
 * (docs/decisiones.md 2026-09-13, decisión "convive con
 * document_types.default_debit_account_id").
 *
 * NO valida la cuenta resuelta (hoja, exigencia de centro de costo): de eso
 * ya es autoridad única PostJournalService, y duplicar esas reglas acá las
 * dejaría desincronizadas a la primera que cambie.
 */
class GlDeterminationResolver
{
    /**
     * Todas las reglas de la compañía en una sola consulta. Una matriz de
     * determinación es configuración —decenas de filas, no miles—, así que
     * traerla entera sale más barato que una consulta por línea.
     */
    public function load(Company $company): Collection
    {
        return GlDetermination::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->get();
    }

    /**
     * Resuelve una categoría que no depende de ningún artículo ni almacén.
     * Es el caso de la transitoria de costos de importación: cuando se acumula
     * el rubro todavía no se sabe sobre qué mercancía va a caer, así que la
     * precedencia por artículo/grupo/almacén no tiene nada con qué operar y la
     * única regla aplicable es la de compañía.
     *
     * @param  Collection<int, GlDetermination>  $rules  lo que devolvió load()
     * @return array{account_id: int, cost_allocation_rule_id: int|null}
     */
    public function resolveForCompany(
        Collection $rules,
        string $category,
        DocumentType $documentType,
        string $side,
    ): array {
        $match = $rules->first(fn (GlDetermination $rule) => $rule->category === $category
            && $rule->scope_level === 'company');

        if ($match) {
            return [
                'account_id' => $match->account_id,
                'cost_allocation_rule_id' => $match->cost_allocation_rule_id,
            ];
        }

        $fallback = $side === 'debit'
            ? $documentType->default_debit_account_id
            : $documentType->default_credit_account_id;

        if ($fallback) {
            return ['account_id' => $fallback, 'cost_allocation_rule_id' => null];
        }

        $label = GlDetermination::CATEGORIES[$category] ?? $category;

        throw new MissingGlDeterminationException(
            "No hay cuenta configurada para \"{$label}\" a nivel de compañía. ".
            "Configurala en la determinación de cuentas o dejá una cuenta por defecto en el tipo de documento {$documentType->code}."
        );
    }

    /**
     * @param  Collection<int, GlDetermination>  $rules  lo que devolvió load()
     * @param  'debit'|'credit'  $side  solo decide cuál cuenta por defecto del
     *                                  tipo de documento se usa como último recurso
     * @return array{account_id: int, cost_allocation_rule_id: int|null}
     */
    /**
     * $warehouse nulo: la operación no está atada a un almacén y el nivel de
     * almacén se salta, igual que se salta el de grupo cuando el artículo no
     * tiene grupo. Es el caso del deterioro de NIC 2, que se avalúa por
     * artículo —el valor neto realizable es una condición del mercado, no del
     * estante donde está guardado— pero que sí puede tener cuentas distintas
     * por artículo o por grupo.
     *
     * Se resolvió como parámetro nulable y no como un método aparte para que
     * la precedencia siga viviendo en UN solo lugar: dos implementaciones de
     * la misma escalera es exactamente lo que esta clase existe para evitar.
     */
    public function resolve(
        Collection $rules,
        string $category,
        Item $item,
        ?Warehouse $warehouse,
        DocumentType $documentType,
        string $side,
    ): array {
        $scopeIds = [
            'item' => $item->id,
            'item_group' => $item->item_group_id,
            'warehouse' => $warehouse?->id,
            'company' => null,
        ];

        // GlDetermination::SCOPE_LEVELS ya está ordenado del más específico
        // al más general; ese orden ES la precedencia.
        foreach (array_keys(GlDetermination::SCOPE_LEVELS) as $level) {
            $scopeId = $scopeIds[$level];

            // Un artículo sin grupo no puede casar con una regla de grupo:
            // sin este corte, scope_id null casaría con la regla de compañía
            // (que también lo tiene null) en el nivel equivocado.
            if ($level !== 'company' && $scopeId === null) {
                continue;
            }

            $match = $rules->first(fn (GlDetermination $rule) => $rule->category === $category
                && $rule->scope_level === $level
                && (string) $rule->scope_id === (string) $scopeId);

            if ($match) {
                return [
                    'account_id' => $match->account_id,
                    'cost_allocation_rule_id' => $match->cost_allocation_rule_id,
                ];
            }
        }

        $fallback = $side === 'debit'
            ? $documentType->default_debit_account_id
            : $documentType->default_credit_account_id;

        if ($fallback) {
            return ['account_id' => $fallback, 'cost_allocation_rule_id' => null];
        }

        $label = GlDetermination::CATEGORIES[$category] ?? $category;

        throw new MissingGlDeterminationException(
            "No hay cuenta configurada para \"{$label}\" en el artículo {$item->code} (almacén {$warehouse->code}). ".
            "Configurala en la determinación de cuentas —a nivel de artículo, grupo, almacén o compañía— o dejá una cuenta por defecto en el tipo de documento {$documentType->code}."
        );
    }
}
