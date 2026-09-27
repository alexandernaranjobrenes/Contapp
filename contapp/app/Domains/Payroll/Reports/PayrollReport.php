<?php

namespace App\Domains\Payroll\Reports;

use App\Domains\Reporting\Reports\TabularReport;

/**
 * El contrato de un reporte de planilla: el tabular compartido.
 *
 * Se conserva un nombre propio del dominio por lo mismo que en inventario: el
 * registro y el controlador de planillas tipan contra él, y si algún día un
 * reporte de planillas necesita declarar algo que solo tiene sentido acá, el
 * lugar ya existe.
 *
 * Todo lo que el contrato pide está documentado en TabularReport. Un reporte
 * cuyas columnas escoge el usuario implementa además HasSelectableColumns.
 */
interface PayrollReport extends TabularReport {}
