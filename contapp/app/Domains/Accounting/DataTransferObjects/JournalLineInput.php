<?php

namespace App\Domains\Accounting\DataTransferObjects;

/**
 * Línea "natural": el usuario digita el monto en la moneda local o extranjera
 * de la compañía (nunca las tres); PostJournalService deriva las otras dos.
 */
class JournalLineInput
{
    public readonly string $debit;

    public readonly string $credit;

    public readonly ?string $taxableBase;

    public function __construct(
        public readonly int $accountId,
        public readonly int $currencyId,
        int|float|string $debit,
        int|float|string $credit,
        public readonly ?string $description = null,
        public readonly ?int $businessPartnerId = null,
        public readonly ?int $costAllocationRuleId = null,
        public readonly ?string $dueDate = null,
        public readonly ?string $referenceDocument = null,
        public readonly bool $opensItem = false,
        public readonly ?int $taxRateId = null,
        int|float|string|null $taxableBase = null,
        public readonly bool $localOnly = false,
        public readonly bool $allowZeroAmount = false,
        public readonly ?string $electronicKey = null,
        public readonly ?int $applyToOpenItemId = null,
        public readonly ?string $referenceDocumentDate = null,
        public readonly ?string $frozenExchangeRate = null,
        /**
         * Centro de costo DIRECTO de la línea: la línea entera carga a un
         * solo centro. Es el caso corriente —el salario de un empleado, el
         * gasto de un departamento—; la norma de reparto es para cuando un
         * mismo monto hay que distribuirlo entre varios.
         */
        public readonly ?int $costCenterId = null,
    ) {
        $this->debit = number_format((float) $debit, 2, '.', '');
        $this->credit = number_format((float) $credit, 2, '.', '');
        $this->taxableBase = $taxableBase === null ? null : number_format((float) $taxableBase, 2, '.', '');

        if (bccomp($this->debit, '0.00', 2) > 0 && bccomp($this->credit, '0.00', 2) > 0) {
            throw new \InvalidArgumentException('Una línea de asiento no puede tener débito y crédito a la vez.');
        }

        // Un borrador (allowZeroAmount) puede guardar una línea todavía sin
        // monto — "tal vez faltó algo por terminar"; al contabilizar en
        // serio (post(), siempre con allowZeroAmount=false) esto sigue
        // siendo obligatorio.
        if (! $allowZeroAmount && bccomp($this->debit, '0.00', 2) === 0 && bccomp($this->credit, '0.00', 2) === 0) {
            throw new \InvalidArgumentException('Una línea de asiento requiere un monto de débito o crédito mayor a cero.');
        }

        if ($this->opensItem && $businessPartnerId === null) {
            throw new \InvalidArgumentException('Una línea que abre partida (opensItem) requiere un socio de negocio.');
        }

        if ($this->opensItem && $this->localOnly) {
            throw new \InvalidArgumentException('Una línea localOnly (ajuste puro de LC) no puede abrir partida.');
        }

        // Cada línea elige UNA sola cosa: o abre una partida nueva (vencimiento)
        // o cancela una existente (aplicación) — nunca ambas (ver
        // docs/decisiones.md 2026-08-24, respuesta del usuario: "una partida
        // por línea").
        if ($this->opensItem && $this->applyToOpenItemId !== null) {
            throw new \InvalidArgumentException('Una línea no puede abrir partida (opensItem) y aplicar a una partida existente a la vez.');
        }

        if ($this->applyToOpenItemId !== null && $businessPartnerId === null) {
            throw new \InvalidArgumentException('Una línea que aplica a una partida existente (applyToOpenItemId) requiere un socio de negocio.');
        }

        if ($this->taxRateId !== null && $this->taxableBase === null) {
            throw new \InvalidArgumentException('Una línea con taxRateId requiere taxableBase.');
        }

        // Una línea con norma de reparto se explota en varias filas al
        // contabilizar (ver PostJournalService::post()) — abrir/aplicar
        // partida e impuesto asumen exactamente 1 fila por línea de entrada,
        // y en la práctica una cuenta de costo/gasto (la única que exige
        // norma de reparto) nunca es una cuenta de control CxC/CxP ni de
        // impuesto, así que esta combinación no tiene un caso real.
        if ($this->costAllocationRuleId !== null && ($this->opensItem || $this->applyToOpenItemId !== null || $this->taxRateId !== null)) {
            throw new \InvalidArgumentException('Una línea con norma de reparto no puede abrir/aplicar partida ni llevar impuesto a la vez.');
        }

        // Centro directo y norma de reparto son dos respuestas distintas a la
        // misma pregunta. Aceptar ambas obligaría a decidir en silencio cuál
        // gana, y esa decisión quedaría escondida dentro del motor.
        if ($this->costCenterId !== null && $this->costAllocationRuleId !== null) {
            throw new \InvalidArgumentException('Una línea no puede llevar centro de costo directo y norma de reparto a la vez.');
        }

        // El inventario es una partida NO monetaria (NIC 21): su costo queda
        // congelado al tipo de cambio de adquisición y no se revalúa. Sin
        // esto, una salida de inventario se convertiría a moneda extranjera
        // al TC del día de la salida en vez del TC con el que la mercancía
        // entró, y el costo unitario en FC se distorsionaría solo en cada
        // movimiento (docs/decisiones.md 2026-09-13).
        if ($this->frozenExchangeRate !== null) {
            if (bccomp($this->frozenExchangeRate, '0', 10) <= 0) {
                throw new \InvalidArgumentException('El tipo de cambio congelado de una línea debe ser mayor a cero.');
            }

            if ($this->localOnly) {
                throw new \InvalidArgumentException('Una línea localOnly no deriva moneda extranjera; no admite tipo de cambio congelado.');
            }
        }
    }

    public function isDebit(): bool
    {
        return bccomp($this->debit, '0.00', 2) > 0;
    }

    public function amount(): string
    {
        return $this->isDebit() ? $this->debit : $this->credit;
    }
}
