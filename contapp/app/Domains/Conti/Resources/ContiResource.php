<?php

namespace App\Domains\Conti\Resources;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un conjunto de datos que Conti puede consultar (ContiResourceCatalog).
 *
 * - screens: con Lectura en cualquiera de esas pantallas del menú alcanza.
 *   Una lista vacía es un catálogo abierto a toda la compañía (como en la
 *   aplicación, los indicadores de impuesto).
 * - query: la consulta base. Pasa por el CompanyScope de los modelos; una
 *   tabla sin company_id propio se filtra por su padre (whereHas).
 * - row y detail: arman cada registro con una lista explícita de campos, en
 *   español. Lo que no está ahí, Conti no lo ve.
 * - filters: nombre del parámetro => [qué acepta, cómo filtra].
 */
final class ContiResource
{
    /**
     * @param  list<string>  $screens
     * @param  Closure(): Builder  $query
     * @param  Closure(Model): array  $row
     * @param  (Closure(Model): array)|null  $detail
     * @param  list<string>|Closure(Builder, string): void  $search
     * @param  array<string, array{0: string, 1: Closure(Builder, string): void}>  $filters
     * @param  array<string, string>  $orderBy
     * @param  (Closure(Builder, array): void)|null  $defaultFilter
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $screens,
        public readonly Closure $query,
        public readonly Closure $row,
        public readonly ?Closure $detail = null,
        public readonly array|Closure $search = [],
        public readonly array $filters = [],
        public readonly ?string $dateColumn = null,
        public readonly array $orderBy = ['id' => 'asc'],
        public readonly ?string $codeColumn = 'code',
        // Lo que se aplica cuando no se pide otra cosa (ej. las partidas
        // abiertas: solo las pendientes, salvo que se filtre por estado).
        public readonly ?Closure $defaultFilter = null,
    ) {}

    public function newQuery(): Builder
    {
        return ($this->query)();
    }

    public function present(Model $model): array
    {
        return ($this->row)($model);
    }

    public function presentDetail(Model $model): array
    {
        return $this->detail ? ($this->detail)($model) : ($this->row)($model);
    }

    /** Lo que se le cuenta al agente de este conjunto. */
    public function describe(): array
    {
        return [
            'clave' => $this->key,
            'nombre' => $this->label,
            'descripcion' => $this->description,
            'busqueda' => $this->search !== [] ? 'buscar=texto' : null,
            'rango_de_fechas' => $this->dateColumn ? 'desde=AAAA-MM-DD, hasta=AAAA-MM-DD' : null,
            'filtros' => array_map(fn (array $filter) => $filter[0], $this->filters),
            'detalle_por' => $this->codeColumn ? 'id o código' : 'id',
        ];
    }
}
