<?php

namespace App\Domains\Payroll\DataTransferObjects;

/**
 * El resultado de cargar el XLSX de movimientos.
 *
 * Lleva `removed` además de `created` porque la carga REEMPLAZA los
 * movimientos digitados del período, y el usuario tiene derecho a saber
 * cuántos se llevó por delante — no solo cuántos entraron.
 */
class PayrollInputImportResult
{
    /**
     * @param  string[]  $errors
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $removed = 0,
        public readonly int $employees = 0,
        public readonly array $errors = [],
        public readonly ?string $fatal = null,
    ) {}

    public function failed(): bool
    {
        return $this->fatal !== null || $this->errors !== [];
    }

    public function summary(): string
    {
        if ($this->fatal !== null) {
            return $this->fatal;
        }

        $removed = $this->removed > 0
            ? " Se reemplazaron {$this->removed} movimiento(s) que había antes."
            : '';

        return "Se cargaron {$this->created} movimiento(s) de {$this->employees} trabajador(es).{$removed}".
            ' Recalculá la planilla para que se apliquen.';
    }
}
