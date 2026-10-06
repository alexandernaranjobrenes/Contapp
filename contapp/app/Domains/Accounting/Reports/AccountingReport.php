<?php

namespace App\Domains\Accounting\Reports;

use App\Domains\Reporting\Reports\TabularReport;

/**
 * El contrato de un reporte de contabilidad: el tabular compartido.
 *
 * Los seis reportes financieros que ya existían —balance de comprobación,
 * estado de resultados, balance general, antigüedad, proyección y
 * comparativo— son controladores hechos a mano, cada uno con su pantalla y su
 * exportación. No se tocan: están probados y en uso.
 *
 * Este registro es para los reportes TABULARES de contabilidad, los que son
 * una lista de movimientos con filtros: el libro mayor y el libro diario. Para
 * esos, escribir controlador, exportador y vista a mano sería repetir por
 * tercera vez lo que inventario y planillas ya resolvieron.
 */
interface AccountingReport extends TabularReport {}
