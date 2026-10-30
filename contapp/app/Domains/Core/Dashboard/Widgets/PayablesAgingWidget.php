<?php

namespace App\Domains\Core\Dashboard\Widgets;

/** Las cuentas por pagar de hoy, por antigüedad. */
class PayablesAgingWidget extends AgingWidget
{
    public function key(): string
    {
        return 'payables_aging';
    }

    public function title(): string
    {
        return 'Cuentas por pagar';
    }

    public function description(): string
    {
        return 'Lo que se les debe a los proveedores hoy, por antigüedad: vigente y por tramos de días vencido.';
    }

    protected function partnerType(): string
    {
        return 'supplier';
    }
}
