<?php

namespace App\Domains\Inventory\Reports;

/**
 * Un parámetro de un reporte, declarado. La pantalla arma el control solo
 * con esto, el controlador valida solo con esto, y el resumen que sale
 * impreso en el encabezado se arma solo con esto.
 *
 * Es lo que hace que agregar un reporte no obligue a tocar la pantalla: sin
 * la declaración, cada reporte necesitaría su propio Vue con sus propios
 * filtros, que es como terminan los módulos de reportería con veinte
 * pantallas casi iguales y ninguna igual del todo.
 */
class ReportFilter
{
    public const DATE = 'date';

    public const SELECT = 'select';

    public const BOOLEAN = 'boolean';

    public const TEXT = 'text';

    /**
     * @param  string|null  $optionSource  nombre del catálogo que llena un
     *                                     select (warehouses, item_groups...);
     *                                     lo resuelve el controlador
     * @param  array<string, string>|null  $options  opciones fijas, cuando no
     *                                               salen de una tabla
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
        public readonly mixed $default = null,
        public readonly ?string $optionSource = null,
        public readonly ?array $options = null,
        /** Texto de ayuda: por qué este filtro cambia lo que se está viendo. */
        public readonly ?string $hint = null,
    ) {}

    public function validationRules(int $companyId): array
    {
        return match ($this->type) {
            self::DATE => ['nullable', 'date'],
            self::BOOLEAN => ['nullable', 'boolean'],
            self::SELECT => $this->options !== null
                ? ['nullable', 'string']
                : ['nullable', 'integer'],
            default => ['nullable', 'string', 'max:100'],
        };
    }
}
