<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Core\Models\Company;

/**
 * El contrato de un reporte tabular: columnas, filtros y filas.
 *
 * ── Por qué está acá y no en cada dominio ────────────────────────────────
 *
 * Nació en inventario, con ocho reportes contra las tres salidas de siempre
 * —pantalla, XLSX y PDF—. Cuando planillas necesitó lo mismo había dos
 * caminos: copiar el contrato y sus tres DTO al dominio de planillas, o
 * subirlos acá.
 *
 * Copiarlos habría producido dos abstracciones idénticas que se separan con
 * el tiempo: se arregla el ancho de una columna en el XLSX de inventario y
 * el de planillas se queda atrás, y entonces dos exportaciones del mismo
 * sistema se ven distintas sin ninguna razón. Es el mismo argumento que
 * justificó UN exportador para los ocho reportes de inventario, un nivel más
 * arriba.
 *
 * Lo que NO es esto: el motor de reportería manejado por tablas de
 * configuración que el proyecto descartó explícitamente el 2026-08-27. Un
 * reporte sigue siendo una CLASE —con su consulta, sus reglas y sus
 * comentarios— y el registro de cada dominio es una lista en código. Lo que
 * se comparte es la forma de la salida, no la definición del reporte.
 *
 * Un reporte nuevo es una clase que implementa esto más una línea en el
 * registro de su dominio. No hay que tocar el controlador, ni la pantalla,
 * ni el exportador, ni el PDF.
 */
interface TabularReport
{
    /** Identificador en la URL. */
    public function code(): string;

    public function label(): string;

    /** Para qué sirve, en una frase, tal como se lee en el índice. */
    public function description(): string;

    /**
     * Qué decisión ayuda a tomar. Es lo que un índice de reportes casi nunca
     * dice y lo que de verdad distingue uno de otro cuando hay diez.
     */
    public function decision(): string;

    /** Agrupa el índice. Cada dominio define sus grupos y su orden. */
    public function group(): string;

    /**
     * Cuántas columnas de la izquierda son de IDENTIDAD y se congelan al
     * desplazar la tabla de lado. Lo declara cada reporte porque no es
     * siempre lo mismo: en un catálogo son código y nombre, pero en un
     * reporte de movimientos la referencia es la fecha.
     */
    public function frozenColumns(): int;

    /** @return ReportFilter[] */
    public function filters(): array;

    /**
     * @param  array<string, mixed>  $filters  ya validados y con sus defaults
     */
    public function build(Company $company, array $filters): ReportResult;
}
