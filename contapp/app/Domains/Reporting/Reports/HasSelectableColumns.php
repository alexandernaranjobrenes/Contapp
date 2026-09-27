<?php

namespace App\Domains\Reporting\Reports;

/**
 * Un reporte cuyas columnas escoge el usuario.
 *
 * ── Por qué es una interfaz aparte y no parte de TabularReport ────────────
 *
 * Porque la mayoría de los reportes NO deben tenerlo. Un balance de
 * comprobación con la columna de crédito apagada no es un balance
 * incompleto: es un documento equivocado. Lo mismo con la planilla de la
 * Caja, donde el juego de columnas es el que la institución espera.
 *
 * Donde sí tiene sentido es en los reportes de CATÁLOGO —la lista de
 * empleados, por ejemplo— porque ahí no hay un juego correcto de columnas:
 * quien prepara un carnet necesita cédula y foto, quien prepara el pago
 * necesita banco y cuenta, y quien revisa cargas necesita número de asegurado
 * y pensionado. Obligar a los tres a llevarse las cuarenta columnas para
 * después borrar a mano es lo que hace que la gente termine manteniendo su
 * propio Excel aparte.
 *
 * Declararlo por separado deja que cada reporte diga si aplica, en vez de
 * agregarle a los treinta un método que a veintiocho no les sirve.
 */
interface HasSelectableColumns
{
    /**
     * Todas las columnas que el reporte puede mostrar, en el orden en que se
     * ofrecen.
     *
     * @return ReportColumn[]
     */
    public function availableColumns(): array;

    /**
     * Las que vienen marcadas. Es la respuesta a «qué necesita ver alguien
     * que abre esto por primera vez», no «todas»: un reporte que arranca con
     * cuarenta columnas se abre una vez y no se vuelve a abrir.
     *
     * @return string[] claves de columna
     */
    public function defaultColumns(): array;
}
