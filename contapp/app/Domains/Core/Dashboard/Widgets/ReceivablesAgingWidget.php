<?php

namespace App\Domains\Core\Dashboard\Widgets;

/** Las cuentas por cobrar de hoy, por antigüedad. */
class ReceivablesAgingWidget extends AgingWidget
{
    public function key(): string
    {
        return 'receivables_aging';
    }

    public function title(): string
    {
        return 'Cuentas por cobrar';
    }

    public function description(): string
    {
        return 'Lo que deben los clientes hoy, por antigüedad: vigente y por tramos de días vencido.';
    }

    protected function partnerType(): string
    {
        return 'client';
    }
}
