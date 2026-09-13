<?php

namespace App\Domains\Accounting\DataTransferObjects;

use JsonSerializable;

class LedgerResult implements JsonSerializable
{
    /**
     * @param  LedgerMovement[]  $movements
     */
    public function __construct(
        public readonly string $ownerCode,
        public readonly string $ownerName,
        public readonly string $normalBalance,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $openingBalance,
        public readonly string $closingBalance,
        public readonly array $movements,
        public readonly bool $truncated,
    ) {}

    /**
     * snake_case explícito: el resto de la app expone JSON/props de Inertia
     * en snake_case (son columnas de Eloquent tal cual); estos DTOs son la
     * excepción por ser objetos PHP simples, así que se traducen a mano acá
     * en vez de dejar que json_encode() saque camelCase de las propiedades.
     */
    public function jsonSerialize(): array
    {
        return [
            'owner_code' => $this->ownerCode,
            'owner_name' => $this->ownerName,
            'normal_balance' => $this->normalBalance,
            'from' => $this->from,
            'to' => $this->to,
            'opening_balance' => $this->openingBalance,
            'closing_balance' => $this->closingBalance,
            'movements' => $this->movements,
            'truncated' => $this->truncated,
        ];
    }
}
