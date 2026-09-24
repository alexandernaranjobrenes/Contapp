<?php

namespace App\Domains\Payroll\Exceptions;

/**
 * La planilla no admite la operación: el período ya está contabilizado, no
 * hay empleados que calcular, falta configuración de tasas, o un concepto
 * digitado no existe en la compañía.
 */
class InvalidPayrollException extends \RuntimeException {}
