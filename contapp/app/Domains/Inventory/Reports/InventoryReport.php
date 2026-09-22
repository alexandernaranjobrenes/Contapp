<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;

/**
 * El contrato de un reporte de inventario.
 *
 * Un reporte nuevo es una clase que implementa esto y una línea en
 * InventoryReportRegistry. No hay que tocar el controlador, ni la pantalla,
 * ni el exportador: las tres salidas trabajan contra este contrato.
 */
interface InventoryReport
{
    /** Identificador en la URL. */
    public function code(): string;

    public function label(): string;

    /** Para qué sirve, en una frase, tal como se lee en el índice. */
    public function description(): string;

    /**
     * Qué decisión ayuda a tomar. Es lo que un índice de reportes casi
     * nunca dice y lo que de verdad distingue uno de otro cuando hay diez.
     */
    public function decision(): string;

    /** Agrupa el índice: catálogo, existencias, movimiento, rentabilidad. */
    public function group(): string;

    /** @return ReportFilter[] */
    public function filters(): array;

    /**
     * @param  array<string, mixed>  $filters  ya validados y con sus defaults
     */
    public function build(Company $company, array $filters): ReportResult;
}
