<?php

namespace App\Domains\Conti\Reports;

use Closure;

/**
 * Un reporte que Conti puede pedir (ContiReportCatalog). Corre el mismo
 * servicio que la pantalla del reporte: las cifras son las mismas que la
 * persona ve en CONTAPP.
 */
final class ContiReport
{
    /**
     * @param  list<string>  $screens  con Lectura en cualquiera alcanza
     * @param  array<string, string>  $params  nombre => qué acepta
     * @param  Closure(array): mixed  $run  recibe los parámetros ya validados
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $screens,
        public readonly array $params,
        public readonly Closure $run,
    ) {}

    public function describe(): array
    {
        return [
            'clave' => $this->key,
            'nombre' => $this->label,
            'descripcion' => $this->description,
            'parametros' => $this->params,
        ];
    }
}
