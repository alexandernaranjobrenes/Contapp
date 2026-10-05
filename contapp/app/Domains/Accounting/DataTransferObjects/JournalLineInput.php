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

    /**
     * El reparto ya normalizado: una lista, siempre, aunque sea de un solo
     * elemento. Ver el parámetro $openItemApplications del constructor.
     *
     * @var array<int, array{open_item_id: int, amount: string}>
     */
    public readonly array $openItemApplications;

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
        /**
         * Reparto del monto de la línea entre VARIAS partidas, cada una con
         * su monto: [['open_item_id' => 12, 'amount' => '3000.00'], ...].
         *
         * Es la forma general; applyToOpenItemId es el caso particular de una
         * sola partida por el monto completo, y se normaliza a esto en el
         * constructor para que el motor tenga un solo camino.
         *
         * @var array<int, array{open_item_id: int, amount: string}>
         */
        array $openItemApplications = [],
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
        // o cancela partidas existentes (aplicación) — nunca ambas (ver
        // docs/decisiones.md 2026-08-24).
        if ($this->opensItem && ($this->applyToOpenItemId !== null || $openItemApplications !== [])) {
            throw new \InvalidArgumentException('Una línea no puede abrir partida (opensItem) y aplicar a una partida existente a la vez.');
        }

        if ($this->applyToOpenItemId !== null && $openItemApplications !== []) {
            throw new \InvalidArgumentException(
                'Una línea trae applyToOpenItemId y openItemApplications a la vez: son la misma cosa expresada de dos formas.'
            );
        }

        $this->openItemApplications = $this->normalizeApplications($openItemApplications);

        if ($this->openItemApplications !== [] && $businessPartnerId === null) {
            throw new \InvalidArgumentException('Una línea que aplica a partidas existentes requiere un socio de negocio.');
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
        if ($this->costAllocationRuleId !== null && ($this->opensItem || $this->openItemApplications !== [] || $this->taxRateId !== null)) {
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

    /**
     * Normaliza el reparto entre partidas y comprueba la regla que lo
     * gobierna.
     *
     * ── Lo aplicado tiene que ser EXACTAMENTE el monto de la línea ───────
     *
     * Un pago de ₡5.334.337,52 repartido entre tres facturas tiene que sumar
     * ₡5.334.337,52. Ni más —eso sería aplicar plata que no entró— ni menos
     * —eso dejaría un sobrante sin destino contable, que es como aparecen las
     * diferencias que nadie sabe de dónde salieron—.
     *
     * Si el socio pagó de más y sobra, eso es un pago a cuenta y necesita su
     * propia línea contra la cuenta que corresponda: el motor no puede
     * adivinar cuál.
     *
     * @param  array<int, array{open_item_id: int|string, amount: int|float|string}>  $applications
     * @return array<int, array{open_item_id: int, amount: string}>
     */
    private function normalizeApplications(array $applications): array
    {
        // El caso de una sola partida por el monto completo se expresa igual
        // que el general: así el motor recorre siempre una lista y no hay dos
        // caminos distintos que puedan divergir con el tiempo.
        if ($this->applyToOpenItemId !== null) {
            return [['open_item_id' => $this->applyToOpenItemId, 'amount' => $this->amount()]];
        }

        if ($applications === []) {
            return [];
        }

        $normalized = [];
        $seen = [];
        $total = '0.00';

        foreach ($applications as $application) {
            if (! isset($application['open_item_id'])) {
                throw new \InvalidArgumentException('Cada aplicación a partida requiere open_item_id.');
            }

            $id = (int) $application['open_item_id'];

            // Dos renglones contra la misma partida esconden el monto real
            // que se le está aplicando: se suman en uno solo.
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException(
                    "La partida id {$id} aparece dos veces en la misma línea; hay que aplicarle un solo monto."
                );
            }

            $seen[$id] = true;

            $amount = number_format((float) ($application['amount'] ?? 0), 2, '.', '');

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw new \InvalidArgumentException(
                    "El monto aplicado a la partida id {$id} debe ser mayor a cero."
                );
            }

            $normalized[] = ['open_item_id' => $id, 'amount' => $amount];
            $total = bcadd($total, $amount, 2);
        }

        // Un borrador puede estar a medio llenar (allowZeroAmount): ahí la
        // línea todavía no tiene monto contra el cual cuadrar el reparto.
        $lineAmount = $this->amount();

        if ($this->allowZeroAmount && bccomp($lineAmount, '0.00', 2) === 0) {
            return $normalized;
        }

        if (bccomp($total, $lineAmount, 2) !== 0) {
            throw new \InvalidArgumentException(
                "El reparto entre partidas suma {$total} y la línea es de {$lineAmount}: ".
                'lo aplicado tiene que ser exactamente el monto de la línea.'
            );
        }

        return $normalized;
    }
}
