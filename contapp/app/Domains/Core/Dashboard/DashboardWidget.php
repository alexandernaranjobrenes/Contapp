<?php

namespace App\Domains\Core\Dashboard;

/**
 * Un elemento del Panel (CLAUDE.md secc. 44): un gráfico, un resumen o una
 * lista con datos de la compañía.
 *
 * Declara de qué pantallas muestra datos (screens): lo ve quien tiene al
 * menos Lectura en alguna. Lo que además tiene que tener la licencia o la
 * compañía va en requires ('conti', 'electronic_invoicing'). Así un elemento
 * de Inventario que agregó un Administrador no lo ve quien no entra a
 * Inventario: no se le calcula ni se le manda.
 *
 * data() arma lo que dibuja la pantalla, según su forma (kind):
 * - stat: un número, con su comparación y su tendencia;
 * - columns: columnas por período, de una o dos series;
 * - bars: barras horizontales, una por fila;
 * - line: una línea en el tiempo;
 * - list: filas con enlace;
 * - meter: lo usado de un límite.
 * Los montos van en la moneda local de la compañía, como número.
 *
 * Un elemento nuevo es una clase en Dashboard/Widgets y su lugar en
 * DashboardWidgetCatalog. Su clave no cambia nunca: es lo que se guarda.
 */
abstract class DashboardWidget
{
    abstract public function key(): string;

    abstract public function title(): string;

    /** Una frase para el catálogo: qué muestra. */
    abstract public function description(): string;

    /** La sección del menú (ScreenCatalog), o 'conti': agrupa el catálogo y pone el ícono. */
    abstract public function section(): string;

    /** stat | columns | bars | line | list | meter */
    abstract public function kind(): string;

    /** small (una columna) | medium (dos) | large (todo el ancho). */
    public function size(): string
    {
        return 'medium';
    }

    /**
     * Las pantallas de las que muestra datos. Vacío: no depende de una
     * pantalla (lo decide requires()).
     *
     * @return list<string>
     */
    abstract public function screens(): array;

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** Adónde lleva «Ver más», si a alguna pantalla. */
    public function href(DashboardContext $context): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    abstract public function data(DashboardContext $context): array;
}
