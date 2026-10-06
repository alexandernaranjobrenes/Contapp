<?php

namespace App\Domains\Conti\Actions;

/**
 * Lo que Conti dejó listo para guardar.
 *
 * - title y items: lo que la persona lee antes de confirmar.
 * - table: las líneas, si las hay (un asiento, una orden de compra).
 * - payload: lo que execute() necesita, ya resuelto (ids, montos).
 * - input: lo que se guarda para volver a prepararlo al confirmar, con los
 *   valores por defecto ya fijados: si al confirmar el resultado no es el
 *   mismo payload, no se guarda (ContiActionService).
 */
final class PreparedAction
{
    /**
     * @param  list<array{campo: string, valor: string|null}>  $items
     * @param  array{columnas: list<string>, filas: list<list<string|null>>}|null  $table
     */
    public function __construct(
        public readonly string $title,
        public readonly array $items,
        public readonly array $payload,
        public readonly array $input,
        public readonly ?array $table = null,
        public readonly array $warnings = [],
    ) {}

    public function summary(): array
    {
        return [
            'titulo' => $this->title,
            'datos' => $this->items,
            'tabla' => $this->table,
            'avisos' => $this->warnings,
        ];
    }

    public function payloadHash(): string
    {
        return hash('sha256', json_encode($this->payload));
    }
}
