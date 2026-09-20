<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Inventory\Exceptions\InvalidWriteDownException;
use App\Domains\Inventory\Models\InventoryWriteDown;
use App\Domains\Inventory\Models\InventoryWriteDownLine;
use App\Domains\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Deterioro de inventario — NIC 2 §28-33: valuar al MENOR entre costo y
 * valor neto realizable (VNR).
 *
 * ── Lo que NO hace, y es la decisión de fondo ─────────────────────────────
 *
 * No toca el kardex, no cambia cantidades y no rebaja `items.avg_cost_local`.
 * La estimación es un CONTRA-ACTIVO: presenta el inventario neto sin alterar
 * su costo.
 *
 * Rebajar el costo habría sido lo intuitivo y habría roto dos cosas a la vez:
 * el promedio ponderado móvil (que es dependiente de la trayectoria y no se
 * puede "corregir" hacia atrás) y la igualdad entre el kardex y la
 * contabilidad, que es la invariante que sostiene todo el módulo. Con la
 * estimación aparte, el reporte de existencias valorizadas sigue cuadrando
 * contra la cuenta de inventario, y el deterioro se lee en su propia cuenta.
 *
 * ── La reversión, que es la parte que un diseño apurado omite ─────────────
 *
 * NIC 2 §33 EXIGE reversar la estimación cuando desaparecen las circunstancias
 * que la causaron, con tope en lo previamente reconocido. (Es una diferencia
 * real con US GAAP, donde la rebaja es permanente.)
 *
 * Acá eso no es un caso especial con su propio código: cada avalúo calcula la
 * estimación que DEBERÍA existir y contabiliza solo el delta contra la que ya
 * había.
 *
 *     objetivo  = max(0, costo − VNR)
 *     movimiento = objetivo − estimación acumulada
 *
 * Un movimiento positivo deteriora; uno negativo reversa. El tope del §33 sale
 * solo: como el objetivo nunca es negativo, la estimación acumulada no puede
 * bajar de cero, así que jamás se reversa más de lo que se reconoció. Y
 * re-avaluar con el mismo VNR contabiliza cero, que es lo correcto.
 *
 * ── Alcance ──────────────────────────────────────────────────────────────
 *
 * El avalúo es POR ARTÍCULO (NIC 2 §29: partida por partida), no por almacén:
 * el VNR es una condición del mercado, no del estante. El costo sale de
 * InventoryValuationService, el mismo que alimenta el reporte — así la base
 * del deterioro es por construcción idéntica a la que el reporte muestra.
 *
 * El VNR lo digita quien hace el avalúo. No se estima solo: NIC 2 §30 lo hace
 * depender de "la evidencia más fiable disponible", que es un juicio y no una
 * fórmula. El reporte de antigüedad y el de lotes por vencer son los insumos
 * para ese juicio.
 */
class PostInventoryWriteDownService
{
    /** Tipo de documento reservado para el avalúo, creado de oficio. */
    private const DOCUMENT_CODE = 'DET';

    public function __construct(
        private readonly PostJournalService $postJournalService,
        private readonly GlDeterminationResolver $glResolver,
        private readonly InventoryValuationService $valuationService,
    ) {}

    /**
     * Calcula el avalúo sin contabilizar nada. Es lo que alimenta la pantalla
     * de revisión: quien avalúa tiene que poder ver el efecto antes de fijarlo.
     *
     * @param  array<int, array{item_id: int, nrv_unit: string|float, reason?: ?string}>  $assessments
     * @return array<int, array<string, mixed>>
     */
    public function preview(Company $company, string $asOf, array $assessments): array
    {
        $costs = $this->costsByItem($company, $asOf);
        $allowances = $this->allowancesByItem($company, $asOf);

        $rows = [];

        foreach ($assessments as $assessment) {
            $itemId = (int) $assessment['item_id'];
            $cost = $costs[$itemId] ?? null;

            if ($cost === null) {
                throw new InvalidWriteDownException(
                    "El artículo id {$itemId} no tiene existencia al {$asOf}; no hay nada que deteriorar."
                );
            }

            $nrvUnit = $this->money6((string) $assessment['nrv_unit']);

            if (bccomp($nrvUnit, '0.000000', 6) < 0) {
                throw new InvalidWriteDownException('El valor neto realizable no puede ser negativo.');
            }

            $nrvValue = $this->money(bcmul($cost['quantity'], $nrvUnit, 10));
            $costValue = $cost['value'];

            // max(0, costo − VNR): si el VNR está por encima del costo no hay
            // deterioro. NIC 2 no permite revaluar inventario por encima del
            // costo; el menor de los dos sigue siendo el costo.
            $difference = bcsub($costValue, $nrvValue, 2);
            $target = bccomp($difference, '0.00', 2) > 0 ? $difference : '0.00';

            $previous = $allowances[$itemId] ?? '0.00';
            $movement = bcsub($target, $previous, 2);

            $rows[] = [
                'item_id' => $itemId,
                'quantity' => $cost['quantity'],
                'unit_cost_local' => $cost['unit_cost'],
                'cost_value_local' => $costValue,
                'nrv_unit_local' => $nrvUnit,
                'nrv_value_local' => $nrvValue,
                'target_allowance_local' => $target,
                'previous_allowance_local' => $previous,
                'movement_local' => $movement,
                'reason' => $assessment['reason'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{item_id: int, nrv_unit: string|float, reason?: ?string}>  $assessments
     */
    public function post(
        Company $company,
        string $asOf,
        array $assessments,
        ?string $description = null,
        ?int $createdBy = null,
    ): InventoryWriteDown {
        if (empty($assessments)) {
            throw new InvalidWriteDownException('Un avalúo de deterioro requiere al menos un artículo.');
        }

        $itemIds = array_map(fn (array $a) => (int) $a['item_id'], $assessments);

        if (count($itemIds) !== count(array_unique($itemIds))) {
            throw new InvalidWriteDownException('Un mismo artículo no puede avaluarse dos veces en el mismo documento.');
        }

        return DB::transaction(function () use ($company, $asOf, $assessments, $description, $createdBy) {
            $rows = $this->preview($company, $asOf, $assessments);

            // Las líneas sin movimiento no se contabilizan: re-avaluar con el
            // mismo VNR no debe generar un asiento en cero ni ensuciar el
            // historial del artículo.
            $effective = array_values(array_filter(
                $rows,
                fn (array $r) => bccomp($r['movement_local'], '0.00', 2) !== 0
            ));

            if (empty($effective)) {
                throw new InvalidWriteDownException(
                    'El avalúo no cambia la estimación de ningún artículo: no hay nada que contabilizar.'
                );
            }

            $documentType = $this->documentType($company);
            $items = Item::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', array_column($effective, 'item_id'))
                ->get()
                ->keyBy('id');

            $rules = $this->glResolver->load($company);
            $on = new \DateTimeImmutable($asOf);
            $journalLines = [];

            foreach ($effective as $row) {
                $item = $items->get($row['item_id'])
                    ?? throw new InvalidWriteDownException("El artículo id {$row['item_id']} no existe en la compañía.");

                $movement = $row['movement_local'];
                $isImpairment = bccomp($movement, '0.00', 2) > 0;
                $amount = $isImpairment ? $movement : bcmul($movement, '-1', 2);

                // Sin almacén: el deterioro se avalúa por artículo (§29), así
                // que la escalera de precedencia salta ese nivel.
                $expense = $this->glResolver->resolve(
                    $rules, 'write_down_expense', $item, null, $documentType, $isImpairment ? 'debit' : 'credit'
                );

                $allowance = $this->glResolver->resolve(
                    $rules, 'write_down_allowance', $item, null, $documentType, $isImpairment ? 'credit' : 'debit'
                );

                $label = ($isImpairment ? 'Deterioro' : 'Reversión de deterioro')." — {$item->code}";

                // Deterioro:  Debe Gasto      / Haber Estimación
                // Reversión:  Debe Estimación / Haber Gasto  (NIC 2 §33: la
                // reversión se reconoce como MENOR gasto del periodo, no como
                // un ingreso.)
                $journalLines[] = new JournalLineInput(
                    accountId: $expense['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $isImpairment ? $amount : 0,
                    credit: $isImpairment ? 0 : $amount,
                    description: $label,
                    costAllocationRuleId: $expense['cost_allocation_rule_id'],
                );

                $journalLines[] = new JournalLineInput(
                    accountId: $allowance['account_id'],
                    currencyId: $company->local_currency_id,
                    debit: $isImpairment ? 0 : $amount,
                    credit: $isImpairment ? $amount : 0,
                    description: $label,
                    costAllocationRuleId: $allowance['cost_allocation_rule_id'],
                );
            }

            $entry = $this->postJournalService->post(
                company: $company,
                documentType: $documentType,
                documentDate: $on,
                postingDate: $on,
                lines: $journalLines,
                description: $description ?? "Deterioro de inventario al {$asOf}",
                createdBy: $createdBy,
            );

            $writeDown = InventoryWriteDown::create([
                'company_id' => $company->id,
                'document_type_id' => $documentType->id,
                'journal_entry_id' => $entry->id,
                'as_of' => $asOf,
                'description' => $description,
                'status' => 'posted',
                'created_by' => $createdBy,
            ]);

            foreach ($effective as $position => $row) {
                InventoryWriteDownLine::create([
                    'inventory_write_down_id' => $writeDown->id,
                    'line_number' => $position + 1,
                    ...$row,
                ]);
            }

            return $writeDown->load('lines');
        });
    }

    /**
     * Estimación acumulada por artículo hasta la fecha: la SUMA de los
     * movimientos de los avalúos anteriores. No se almacena un saldo aparte —
     * es una suma, y se comporta como cualquier otro saldo del sistema
     * (CLAUDE.md: los saldos no se almacenan).
     *
     * @return array<int, string>
     */
    public function allowancesByItem(Company $company, string $asOf): array
    {
        $rows = DB::table('inventory_write_down_lines')
            ->join('inventory_write_downs', 'inventory_write_downs.id', '=', 'inventory_write_down_lines.inventory_write_down_id')
            ->where('inventory_write_downs.company_id', $company->id)
            ->where('inventory_write_downs.status', 'posted')
            ->whereDate('inventory_write_downs.as_of', '<=', $asOf)
            ->groupBy('inventory_write_down_lines.item_id')
            ->get([
                'inventory_write_down_lines.item_id',
                DB::raw('SUM(inventory_write_down_lines.movement_local) as allowance'),
            ]);

        $allowances = [];

        foreach ($rows as $row) {
            $allowances[(int) $row->item_id] = $this->money((string) $row->allowance);
        }

        return $allowances;
    }

    /**
     * Costo por artículo al corte, consolidando los almacenes. Sale del mismo
     * servicio que alimenta el reporte de existencias valorizadas: la base del
     * deterioro tiene que ser idéntica a la que el reporte muestra, o el
     * avalúo estaría rebajando un costo que nadie más ve.
     *
     * @return array<int, array{quantity: string, value: string, unit_cost: string}>
     */
    public function costsByItem(Company $company, string $asOf): array
    {
        $byItem = [];

        foreach ($this->valuationService->build($company, $asOf)->rows as $row) {
            $current = $byItem[$row->itemId] ?? ['quantity' => '0.000000', 'value' => '0.00'];

            $byItem[$row->itemId] = [
                'quantity' => bcadd($current['quantity'], $row->quantity, 6),
                'value' => bcadd($current['value'], $row->valueLocal, 2),
            ];
        }

        foreach ($byItem as $itemId => $data) {
            $byItem[$itemId]['unit_cost'] = bccomp($data['quantity'], '0.000000', 6) === 0
                ? '0.000000'
                : bcdiv($data['value'], $data['quantity'], 6);
        }

        return $byItem;
    }

    /**
     * Tipo de documento reservado del avalúo, creado de oficio la primera vez
     * que hace falta — mismo criterio que el ACC del cierre anual y el APE de
     * saldos iniciales: es un documento del sistema, no uno que el usuario
     * tenga que configurar para poder deteriorar.
     */
    public function documentType(Company $company): DocumentType
    {
        return DocumentType::withoutGlobalScope(CompanyScope::class)->firstOrCreate(
            ['company_id' => $company->id, 'code' => self::DOCUMENT_CODE],
            [
                'name' => 'Avalúo de deterioro de inventario',
                'origin_module' => 'inventario',
                'generates_journal' => true,
                'currency_mode' => 'local_fija',
                'status' => 'active',
            ],
        );
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function money6(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
