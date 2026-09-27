<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Reporting\Reports\TabularReport;

/**
 * El contrato de un reporte de inventario: el tabular compartido, sin
 * agregados.
 *
 * Se conserva el nombre propio del dominio —en vez de usar TabularReport
 * directo— porque el registro y el controlador de inventario tipan contra él,
 * y porque el día que un reporte de inventario necesite declarar algo que
 * solo tiene sentido acá (un almacén por defecto, un método de costeo) el
 * lugar de agregarlo ya existe y no arrastra a los demás dominios.
 *
 * Todo lo que el contrato pide está documentado en TabularReport.
 */
interface InventoryReport extends TabularReport {}
