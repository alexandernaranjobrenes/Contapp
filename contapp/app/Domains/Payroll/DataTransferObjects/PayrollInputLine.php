<?php

namespace App\Domains\Payroll\DataTransferObjects;

/**
 * Un movimiento que se le digita a un empleado para un período: horas extra,
 * una bonificación, un rebajo de préstamo, un adelanto ya entregado.
 *
 * Lo que NO se digita es el salario base ni las cargas sociales: el primero
 * viene de la ficha y las segundas las calcula el motor. Dejar digitar una
 * carga social permitiría "cuadrar" una planilla a mano y romper la
 * conciliación con la Caja sin que nada lo avise.
 */
class PayrollInputLine
{
    public function __construct(
        public readonly int $employeeId,
        public readonly int $conceptId,
        /** Monto, porcentaje base o cantidad de horas, según el concepto. */
        public readonly string|float|int|null $amount = null,
        public readonly string|float|int|null $quantity = null,
        public readonly ?string $notes = null,
    ) {}
}
