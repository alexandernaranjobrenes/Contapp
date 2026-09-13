<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\BpLineRequirementException;
use App\Domains\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domains\Accounting\Exceptions\CostCenterNotPostableException;
use App\Domains\Accounting\Exceptions\InvalidCostAllocationRuleException;
use App\Domains\Accounting\Exceptions\InvalidOpenItemException;
use App\Domains\Accounting\Exceptions\InvalidTaxAmountException;
use App\Domains\Accounting\Exceptions\JournalEntryNotDraftException;
use App\Domains\Accounting\Exceptions\JournalEntryNotPostedException;
use App\Domains\Accounting\Exceptions\MissingBusinessPartnerException;
use App\Domains\Accounting\Exceptions\MissingCostAllocationRuleException;
use App\Domains\Accounting\Exceptions\MissingExchangeRateException;
use App\Domains\Accounting\Exceptions\NonPostingAccountException;
use App\Domains\Accounting\Exceptions\NoOpenFiscalPeriodException;
use App\Domains\Accounting\Exceptions\NumberSeriesExhaustedException;
use App\Domains\Accounting\Exceptions\TaxRateNotEffectiveException;
use App\Domains\Accounting\Exceptions\UnbalancedJournalEntryException;
use App\Domains\Accounting\Exceptions\UnreversibleJournalEntryException;
use App\Domains\Accounting\Models\AccountReconciliationLine;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostAllocationRuleLine;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BpPaymentApplication;
use App\Domains\BusinessPartners\Services\ApplyPaymentService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\DocumentTypeNumberSeries;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Tax\Models\JournalDetailTax;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Support\Facades\DB;

/**
 * Único punto de escritura de asientos contables. Aplica, en este orden,
 * las reglas innegociables de CLAUDE.md: período abierto, solo cuentas hoja,
 * partida doble exacta en las 3 monedas (aritmética decimal, no float).
 *
 * Recibe $company explícitamente y bypasea el CompanyScope ambiental en sus
 * lecturas internas: un job en background (ej. revaluación programada) no
 * siempre tiene CurrentCompany seteado, y este service no debe depender de
 * ese estado implícito para ser correcto.
 */
class PostJournalService
{
    public function __construct(
        private readonly ApplyPaymentService $applyPaymentService,
        private readonly CostAllocationSplitter $costAllocationSplitter,
    ) {}

    /**
     * @param  JournalLineInput[]  $lines
     */
    public function post(
        Company $company,
        DocumentType $documentType,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        array $lines,
        ?string $description = null,
        ?int $createdBy = null,
        ?int $numberSeriesId = null,
        ?JournalEntry $draftToFinalize = null,
        ?string $manualExchangeRate = null,
        ?\DateTimeInterface $dueDate = null,
    ): JournalEntry {
        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Un asiento requiere al menos una línea.');
        }

        if ($draftToFinalize !== null
            && ($draftToFinalize->status !== 'draft' || $draftToFinalize->company_id !== $company->id)) {
            throw new JournalEntryNotDraftException('Este asiento ya no está en borrador; no se puede volver a contabilizar.');
        }

        return DB::transaction(function () use ($company, $documentType, $documentDate, $postingDate, $lines, $description, $createdBy, $numberSeriesId, $draftToFinalize, $manualExchangeRate, $dueDate) {
            // posting_date (fecha de contabilización) es la fecha RECTORA:
            // fija período fiscal, tipo de cambio y vigencias de cuenta/tarifa.
            // document_date (fecha del documento fuente, ej. la factura del
            // proveedor) es puramente informativa desde acá en adelante — se
            // guarda tal cual pero no participa en ninguna de estas reglas
            // (docs/decisiones.md 2026-08-22).
            $period = $this->resolveOpenPeriod($company, $postingDate);

            $accounts = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', array_map(fn (JournalLineInput $l) => $l->accountId, $lines))
                ->get()
                ->keyBy('id');

            // Bulk-load explícito por consultas planas (no ->with('lines.costCenter')):
            // CostCenter/CostAllocationRule traen CompanyScope automático vía
            // BelongsToCompany, y este service debe ser correcto sin
            // CurrentCompany ambiental (ej. un job en background) — mismo
            // bypass ya usado en todo el resto del archivo.
            $ruleIds = array_values(array_unique(array_filter(
                array_map(fn (JournalLineInput $l) => $l->costAllocationRuleId, $lines)
            )));

            $rules = CostAllocationRule::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', $ruleIds)
                ->get()
                ->keyBy('id');

            $ruleLinesByRule = CostAllocationRuleLine::whereIn('cost_allocation_rule_id', $ruleIds)
                ->orderBy('position')
                ->get()
                ->groupBy('cost_allocation_rule_id');

            $ruleCostCenterIds = $ruleLinesByRule->flatten()->pluck('cost_center_id')->unique()->values();

            $ruleCostCenters = CostCenter::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', $ruleCostCenterIds)
                ->get()
                ->keyBy('id');

            $computedLines = [];
            $computedLineOrigins = [];
            $openItemLines = [];
            $applyItemLines = [];
            $taxLines = [];
            $totals = [
                'local' => ['debit' => '0.00', 'credit' => '0.00'],
                'foreign' => ['debit' => '0.00', 'credit' => '0.00'],
                'system' => ['debit' => '0.00', 'credit' => '0.00'],
            ];

            foreach (array_values($lines) as $index => $line) {
                /** @var JournalLineInput $line */
                $account = $accounts->get($line->accountId)
                    ?? throw new NonPostingAccountException("La cuenta id {$line->accountId} no existe en la compañía.");

                if (! $account->accepts_posting) {
                    throw new NonPostingAccountException(
                        "La cuenta {$account->code} ({$account->description_es}) no acepta movimientos: no es cuenta hoja."
                    );
                }

                if ($account->requires_business_partner && $line->businessPartnerId === null) {
                    throw new MissingBusinessPartnerException(
                        "La cuenta {$account->code} ({$account->description_es}) exige socio de negocio en cada línea."
                    );
                }

                if ($account->requires_cost_center && $line->costAllocationRuleId === null) {
                    throw new MissingCostAllocationRuleException(
                        "La cuenta {$account->code} ({$account->description_es}) exige norma de reparto en cada línea."
                    );
                }

                $rule = null;
                $thisRuleLines = null;

                if ($line->costAllocationRuleId !== null) {
                    $rule = $rules->get($line->costAllocationRuleId)
                        ?? throw new CostCenterNotPostableException("La norma de reparto id {$line->costAllocationRuleId} no existe en la compañía.");

                    if (! $rule->isEffectiveOn($postingDate)) {
                        throw new CostCenterNotPostableException(
                            "La norma de reparto {$rule->code} ({$rule->name}) está inactiva o fuera de su vigencia para la fecha {$postingDate->format('Y-m-d')}."
                        );
                    }

                    $thisRuleLines = $ruleLinesByRule->get($rule->id) ?? collect();

                    // Re-chequeo defensivo (igual espíritu que attachTax() con
                    // la tarifa de IVA): CostAllocationRuleController ya
                    // exige 100% al guardar, pero un asiento no debe confiar
                    // ciegamente en que ese dato sigue siendo válido.
                    if ($thisRuleLines->isEmpty() || ! $this->costAllocationSplitter->sumsTo100($thisRuleLines)) {
                        throw new InvalidCostAllocationRuleException(
                            "La norma de reparto {$rule->code} no tiene centros de costo configurados que sumen 100%."
                        );
                    }

                    foreach ($thisRuleLines as $ruleLine) {
                        $costCenter = $ruleCostCenters->get($ruleLine->cost_center_id)
                            ?? throw new CostCenterNotPostableException("El centro de costo id {$ruleLine->cost_center_id} de la norma {$rule->code} no existe en la compañía.");

                        if (! $costCenter->isPostableOn($postingDate)) {
                            throw new CostCenterNotPostableException(
                                "El centro de costo {$costCenter->code} ({$costCenter->name}), parte de la norma {$rule->code}, está inactivo o fuera de su vigencia para la fecha {$postingDate->format('Y-m-d')}."
                            );
                        }
                    }
                }

                // Protocolo de control por tipo de documento (docs/decisiones.md
                // 2026-08-24): solo aplica a líneas CON socio de negocio, y solo
                // al contabilizar en serio (post() nunca corre en saveDraft()).
                // 'due_date' exige opensItem=true (la línea queda como partida
                // pendiente, con vencimiento); 'application' exige
                // applyToOpenItemId (cancela una partida existente); 'either'
                // acepta cualquiera de las dos. JournalLineInput ya garantiza
                // que una línea nunca trae ambas a la vez.
                if ($documentType->bp_line_requirement !== 'none' && $line->businessPartnerId !== null) {
                    $satisfiesDueDate = $line->opensItem;
                    $satisfiesApplication = $line->applyToOpenItemId !== null;

                    $satisfied = match ($documentType->bp_line_requirement) {
                        'due_date' => $satisfiesDueDate,
                        'application' => $satisfiesApplication,
                        'either' => $satisfiesDueDate || $satisfiesApplication,
                        default => true,
                    };

                    if (! $satisfied) {
                        $label = DocumentType::BP_LINE_REQUIREMENTS[$documentType->bp_line_requirement];
                        throw new BpLineRequirementException(
                            "El tipo de documento {$documentType->code} exige \"{$label}\" en cada línea con socio de negocio; ".
                            'la línea con socio id '."{$line->businessPartnerId} no trae ni vencimiento (abre partida) ni aplicación a una partida existente."
                        );
                    }
                }

                if ($line->applyToOpenItemId !== null) {
                    $applyItemLines[$index] = $line;
                }

                $amounts = $this->computeTripleCurrencyAmounts($company, $line, $postingDate, $manualExchangeRate);

                $base = [
                    'account_id' => $line->accountId,
                    'description' => $line->description,
                    'electronic_key' => $line->electronicKey,
                    'business_partner_id' => $line->businessPartnerId,
                    'currency_id' => $amounts['currency_id'],
                    'exchange_rate_lc_fc' => $amounts['exchange_rate_lc_fc'],
                    'exchange_rate_fc_sc' => $amounts['exchange_rate_fc_sc'],
                    // Si la línea no trae su propio vencimiento, hereda el de
                    // encabezado (precarga editable, ver JournalEntries/Create.vue) —
                    // ninguno de los dos es obligatorio.
                    'due_date' => $line->dueDate ?? $dueDate?->format('Y-m-d'),
                    'reference_document' => $line->referenceDocument,
                    'reference_document_date' => $line->referenceDocumentDate,
                ];

                if ($rule !== null) {
                    // Una norma de reparto explota esta línea en N filas
                    // reales — una por centro de costo — cada una con su
                    // porción exacta del monto en las 3 monedas (ver
                    // CostAllocationSplitter). Así el mayor auxiliar por
                    // centro de costo (LedgerService, que lee cost_center_id
                    // directo) no necesita saber nada de normas de reparto.
                    $splits = [];
                    foreach (['local', 'foreign', 'system'] as $bucket) {
                        foreach (['debit', 'credit'] as $side) {
                            $total = $amounts["{$side}_{$bucket}"];
                            $splits["{$side}_{$bucket}"] = bccomp($total, '0.00', 2) > 0
                                ? $this->costAllocationSplitter->split($total, $thisRuleLines)
                                : null;
                        }
                    }

                    foreach ($thisRuleLines as $ruleLine) {
                        $row = $base;
                        $row['cost_center_id'] = $ruleLine->cost_center_id;
                        $row['cost_allocation_rule_id'] = $rule->id;

                        foreach (['local', 'foreign', 'system'] as $bucket) {
                            foreach (['debit', 'credit'] as $side) {
                                $map = $splits["{$side}_{$bucket}"];
                                $row["{$side}_{$bucket}"] = $map !== null ? $map[$ruleLine->cost_center_id] : '0.00';
                            }
                        }

                        $computedLines[] = $row;
                        $computedLineOrigins[] = $index;
                    }
                } else {
                    $row = $base;
                    $row['cost_center_id'] = null;
                    $row['cost_allocation_rule_id'] = null;

                    foreach (['local', 'foreign', 'system'] as $bucket) {
                        foreach (['debit', 'credit'] as $side) {
                            $row["{$side}_{$bucket}"] = $amounts["{$side}_{$bucket}"];
                        }
                    }

                    $computedLines[] = $row;
                    $computedLineOrigins[] = $index;
                }

                if ($line->opensItem) {
                    $openItemLines[$index] = $line;
                }

                if ($line->taxRateId !== null) {
                    $taxLines[$index] = $line;
                }

                foreach (['local', 'foreign', 'system'] as $bucket) {
                    $totals[$bucket]['debit'] = bcadd($totals[$bucket]['debit'], $amounts["debit_{$bucket}"], 2);
                    $totals[$bucket]['credit'] = bcadd($totals[$bucket]['credit'], $amounts["credit_{$bucket}"], 2);
                }
            }

            foreach (['local', 'foreign', 'system'] as $bucket) {
                if (bccomp($totals[$bucket]['debit'], $totals[$bucket]['credit'], 2) !== 0) {
                    throw new UnbalancedJournalEntryException(
                        "El asiento no cuadra en moneda {$bucket}: débitos {$totals[$bucket]['debit']} vs créditos {$totals[$bucket]['credit']}."
                    );
                }
            }

            // document_number: consecutivo interno, automático e inalterable,
            // siempre se asigna sin importar si además se pide una serie manual.
            $documentNumber = $this->nextDocumentNumber($documentType);
            $seriesNumber = $numberSeriesId !== null ? $this->nextSeriesNumber($company, $documentType, $numberSeriesId) : null;

            $attributes = [
                'company_id' => $company->id,
                'document_type_id' => $documentType->id,
                'document_number' => $documentNumber,
                'number_series_id' => $numberSeriesId,
                'series_number' => $seriesNumber,
                'document_date' => $documentDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'due_date' => $dueDate?->format('Y-m-d'),
                'fiscal_period_id' => $period->id,
                'description' => $description,
                'status' => 'posted',
                'source_module' => $documentType->origin_module,
                // created_by conserva quién inició el borrador si lo hay;
                // posted_by siempre es quien contabiliza formalmente ahora,
                // pueden ser dos personas distintas (borrador + revisión).
                'created_by' => $draftToFinalize->created_by ?? $createdBy,
                'posted_by' => $createdBy,
                'posted_at' => now(),
            ];

            if ($draftToFinalize) {
                $draftToFinalize->details()->delete();
                $draftToFinalize->update($attributes);
                $journalEntry = $draftToFinalize;
            } else {
                $journalEntry = JournalEntry::create($attributes);
            }

            // $computedLines puede traer varias filas por línea de entrada
            // (una norma de reparto explota 1 línea en N) — $computedLineOrigins
            // mapea cada posición de vuelta al $index original, que es la
            // clave real de $openItemLines/$applyItemLines/$taxLines.
            // JournalLineInput ya garantiza que una línea con norma de
            // reparto nunca aparece en esos 3 mapas, así que nunca se
            // dispara más de una vez por línea explotada.
            foreach ($computedLines as $pos => $computed) {
                $computed['line_number'] = $pos + 1;
                $originIndex = $computedLineOrigins[$pos];

                $detail = new JournalDetail($computed);
                $journalEntry->details()->save($detail);

                if (isset($openItemLines[$originIndex])) {
                    $this->openBpOpenItem($journalEntry, $documentType, $detail, $openItemLines[$originIndex]);
                }

                if (isset($applyItemLines[$originIndex])) {
                    $this->applyToExistingOpenItem($company, $journalEntry, $applyItemLines[$originIndex], $postingDate, $createdBy);
                }

                if (isset($taxLines[$originIndex])) {
                    $this->attachTax($detail, $taxLines[$originIndex], $postingDate);
                }
            }

            return $journalEntry->load('details');
        });
    }

    /**
     * Anula un asiento ya contabilizado (CLAUDE.md: "nada contabilizado se
     * borra, se anula con asiento de reversión"). $original NUNCA se toca ni
     * se borra — solo cambia a status='voided'. El asiento nuevo es un
     * espejo EXACTO línea por línea (débito↔crédito invertidos en las 3
     * monedas, MISMOS tipos de cambio que el original — no se recalculan al
     * tipo de cambio de hoy) para que cancele perfectamente sin importar
     * cuánto tiempo pasó desde el original.
     */
    public function reverse(
        Company $company,
        JournalEntry $original,
        \DateTimeInterface $postingDate,
        ?string $description = null,
        ?int $createdBy = null,
    ): JournalEntry {
        if ($original->company_id !== $company->id) {
            throw new \InvalidArgumentException('El asiento a anular no pertenece a la compañía indicada.');
        }

        // reverse() siempre deja status='voided' en la misma transacción que
        // crea la reversión (ver abajo), así que este único chequeo ya cubre
        // "no se puede anular un borrador" Y "no se puede volver a anular un
        // asiento ya anulado" — no hace falta una consulta aparte por
        // reversal_of_id, quedaría redundante con este estado.
        if ($original->status !== 'posted') {
            throw new JournalEntryNotPostedException('Solo se pueden anular asientos ya contabilizados (no borradores ni ya anulados).');
        }

        $original->loadMissing('details');

        // $original->documentType (relación) pasaría por el CompanyScope
        // ambiental de DocumentType y volvería null sin CurrentCompany
        // seteado (ej. un job en background) — bypass explícito, mismo
        // criterio que el resto de este service.
        $documentType = DocumentType::withoutGlobalScope(CompanyScope::class)->findOrFail($original->document_type_id);

        return DB::transaction(function () use ($company, $original, $documentType, $postingDate, $description, $createdBy) {
            $period = $this->resolveOpenPeriod($company, $postingDate);

            // Validar TODAS las líneas antes de escribir nada: una partida
            // con pagos ya aplicados, o una línea ya reconciliada
            // internamente, no se puede deshacer solo con un espejo — hace
            // falta que el usuario reversee esas aplicaciones/reconciliaciones
            // primero, para no dejar el sistema en un estado inconsistente.
            foreach ($original->details as $detail) {
                if ($detail->business_partner_id) {
                    $openItem = BpOpenItem::where('origin_journal_detail_id', $detail->id)->first();

                    if ($openItem && bccomp((string) $openItem->applied_amount, '0.00', 2) > 0) {
                        throw new UnreversibleJournalEntryException(
                            "La línea de la cuenta id {$detail->account_id} ya tiene cobros/pagos aplicados a su partida (#{$openItem->id}); no se puede anular directamente. Deshacé esas aplicaciones primero."
                        );
                    }
                }

                if (AccountReconciliationLine::where('journal_detail_id', $detail->id)->exists()) {
                    throw new UnreversibleJournalEntryException(
                        "La línea de la cuenta id {$detail->account_id} ya forma parte de una reconciliación interna; deshacé esa reconciliación primero."
                    );
                }
            }

            $paymentApplications = BpPaymentApplication::where('payment_journal_entry_id', $original->id)->get();

            $documentNumber = $this->nextDocumentNumber($documentType);

            $reversal = JournalEntry::create([
                'company_id' => $company->id,
                'document_type_id' => $original->document_type_id,
                'document_number' => $documentNumber,
                'document_date' => $postingDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'fiscal_period_id' => $period->id,
                'description' => $description ?? "Anulación de {$documentType->code}-{$original->document_number}",
                'status' => 'posted',
                'reversal_of_id' => $original->id,
                'source_module' => $original->source_module,
                'created_by' => $createdBy,
                'posted_by' => $createdBy,
                'posted_at' => now(),
            ]);

            foreach ($original->details as $index => $detail) {
                $mirror = $detail->replicate(['id', 'journal_entry_id', 'created_at', 'updated_at']);
                $mirror->journal_entry_id = $reversal->id;
                $mirror->line_number = $index + 1;
                $mirror->debit_local = $detail->credit_local;
                $mirror->credit_local = $detail->debit_local;
                $mirror->debit_foreign = $detail->credit_foreign;
                $mirror->credit_foreign = $detail->debit_foreign;
                $mirror->debit_system = $detail->credit_system;
                $mirror->credit_system = $detail->debit_system;
                // La clave electrónica es del documento original (la factura
                // que se está anulando), no de este asiento espejo — copiarla
                // acá solo confundiría un reporte de IVA o una validación de
                // duplicados más adelante.
                $mirror->electronic_key = null;
                $mirror->save();

                if ($detail->business_partner_id) {
                    $openItem = BpOpenItem::where('origin_journal_detail_id', $detail->id)->first();

                    // Ya se validó arriba que, si existe, no tiene pagos
                    // aplicados — cerrarla refleja que la deuda que
                    // representaba nunca debió existir, no que se cobró/pagó.
                    if ($openItem && $openItem->status !== 'closed') {
                        $openItem->update([
                            'applied_amount' => $openItem->original_amount,
                            'balance' => '0.00',
                            'status' => 'closed',
                        ]);
                    }
                }
            }

            // Si el asiento que se anula era en sí mismo un pago/cobro que
            // aplicó contra partidas de OTROS documentos, esas aplicaciones
            // también se deshacen: la partida original vuelve a quedar con
            // el saldo pendiente que tenía antes de ese pago.
            foreach ($paymentApplications as $application) {
                $openItem = BpOpenItem::lockForUpdate()->find($application->open_item_id);

                if ($openItem) {
                    $newApplied = bcsub((string) $openItem->applied_amount, (string) $application->applied_amount, 2);
                    $newBalance = bcadd((string) $openItem->balance, (string) $application->applied_amount, 2);

                    $openItem->update([
                        'applied_amount' => $newApplied,
                        'balance' => $newBalance,
                        'status' => bccomp($newBalance, (string) $openItem->original_amount, 2) === 0 ? 'open' : 'partial',
                    ]);
                }

                $application->delete();
            }

            $original->update(['status' => 'voided']);

            return $reversal->load('details');
        });
    }

    /**
     * Guarda un asiento "preliminar": puede estar incompleto (líneas sin
     * monto, no cuadra, cuenta que exigiría socio/norma de reparto sin una
     * todavía) — a propósito no corre casi ninguna de las reglas de post(),
     * porque el punto de un borrador es justamente permitir eso. No asigna
     * document_number, número de serie ni período fiscal (todo eso se
     * resuelve recién al contabilizar formalmente, vía post() con
     * $draftToFinalize) y no calcula moneda extranjera/sistema — solo guarda
     * el monto local tal cual se digitó. Tampoco explota la norma de reparto
     * en varios centros de costo todavía (eso también es cosa de post()): un
     * borrador guarda "1 línea de entrada = 1 fila", con la norma elegida
     * pero sin repartir, para que se pueda seguir editando como una sola
     * línea. Lo único que sí exige: que cada cuenta referenciada exista de
     * verdad en la compañía (columna NOT NULL con FK; no hay forma de
     * guardar "cuenta en blanco").
     *
     * @param  JournalLineInput[]  $lines
     */
    public function saveDraft(
        Company $company,
        DocumentType $documentType,
        \DateTimeInterface $documentDate,
        \DateTimeInterface $postingDate,
        array $lines,
        ?string $description = null,
        ?int $createdBy = null,
        ?JournalEntry $existingDraft = null,
        ?\DateTimeInterface $dueDate = null,
    ): JournalEntry {
        if ($documentType->company_id !== $company->id) {
            throw new \InvalidArgumentException('El tipo de documento no pertenece a la compañía indicada.');
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Un asiento requiere al menos una línea.');
        }

        if ($existingDraft !== null
            && ($existingDraft->status !== 'draft' || $existingDraft->company_id !== $company->id)) {
            throw new JournalEntryNotDraftException('Este asiento ya no está en borrador; no se puede editar como preliminar.');
        }

        return DB::transaction(function () use ($company, $documentType, $documentDate, $postingDate, $lines, $description, $createdBy, $existingDraft, $dueDate) {
            $existingAccountIds = ChartOfAccount::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->whereIn('id', array_map(fn (JournalLineInput $l) => $l->accountId, $lines))
                ->pluck('id');

            foreach ($lines as $line) {
                /** @var JournalLineInput $line */
                if (! $existingAccountIds->contains($line->accountId)) {
                    throw new NonPostingAccountException("La cuenta id {$line->accountId} no existe en la compañía.");
                }
            }

            $attributes = [
                'company_id' => $company->id,
                'document_type_id' => $documentType->id,
                'document_date' => $documentDate->format('Y-m-d'),
                'posting_date' => $postingDate->format('Y-m-d'),
                'due_date' => $dueDate?->format('Y-m-d'),
                'description' => $description,
                'status' => 'draft',
                'source_module' => $documentType->origin_module,
                'created_by' => $existingDraft->created_by ?? $createdBy,
            ];

            if ($existingDraft) {
                $existingDraft->details()->delete();
                $existingDraft->update($attributes);
                $journalEntry = $existingDraft;
            } else {
                $journalEntry = JournalEntry::create($attributes);
            }

            foreach (array_values($lines) as $index => $line) {
                /** @var JournalLineInput $line */
                $isDebit = $line->isDebit();
                $local = $line->amount();

                $journalEntry->details()->create([
                    'line_number' => $index + 1,
                    'account_id' => $line->accountId,
                    'description' => $line->description,
                    'electronic_key' => $line->electronicKey,
                    'business_partner_id' => $line->businessPartnerId,
                    'cost_allocation_rule_id' => $line->costAllocationRuleId,
                    'currency_id' => $line->currencyId,
                    'debit_local' => $isDebit ? $local : '0.00',
                    'credit_local' => $isDebit ? '0.00' : $local,
                    'debit_foreign' => '0.00',
                    'credit_foreign' => '0.00',
                    'debit_system' => '0.00',
                    'credit_system' => '0.00',
                    'due_date' => $line->dueDate ?? $dueDate?->format('Y-m-d'),
                    'reference_document' => $line->referenceDocument,
                    'reference_document_date' => $line->referenceDocumentDate,
                ]);
            }

            return $journalEntry->load('details');
        });
    }

    private function resolveOpenPeriod(Company $company, \DateTimeInterface $date): FiscalPeriod
    {
        $period = FiscalPeriod::whereHas('fiscalYear', function ($q) use ($company) {
            $q->withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id);
        })
            ->whereDate('start_date', '<=', $date->format('Y-m-d'))
            ->whereDate('end_date', '>=', $date->format('Y-m-d'))
            ->first();

        if (! $period) {
            throw new NoOpenFiscalPeriodException(
                "No existe un período fiscal configurado para la fecha {$date->format('Y-m-d')}."
            );
        }

        if ($period->status !== 'open') {
            throw new ClosedFiscalPeriodException(
                "El período fiscal #{$period->period_number} ({$period->start_date->format('Y-m-d')} a {$period->end_date->format('Y-m-d')}) está '{$period->status}'; no se puede registrar."
            );
        }

        return $period;
    }

    private function computeTripleCurrencyAmounts(Company $company, JournalLineInput $line, \DateTimeInterface $date, ?string $manualExchangeRate = null): array
    {
        if ($line->localOnly) {
            // Ajuste puro de moneda local (diferencial cambiario, redondeos...):
            // no deriva FC/SC porque, por definición, el saldo en moneda
            // extranjera NO cambia cuando se revalúa, solo su equivalente en LC.
            if ($line->currencyId !== $company->local_currency_id) {
                throw new \InvalidArgumentException('Una línea localOnly debe declararse en la moneda local de la compañía.');
            }

            $local = $line->amount();
            $isDebit = $line->isDebit();

            return [
                'currency_id' => $line->currencyId,
                'exchange_rate_lc_fc' => null,
                'exchange_rate_fc_sc' => null,
                'debit_local' => $isDebit ? $local : '0.00',
                'credit_local' => $isDebit ? '0.00' : $local,
                'debit_foreign' => '0.00',
                'credit_foreign' => '0.00',
                'debit_system' => '0.00',
                'credit_system' => '0.00',
            ];
        }

        if ($line->currencyId === $company->local_currency_id) {
            $rateLcFc = $manualExchangeRate ?? $this->rateOnOrBefore($company, $company->foreign_currency_id, $date);
            $local = $line->amount();
            $foreign = $this->money(bcdiv($local, $rateLcFc, 10));
        } elseif ($line->currencyId === $company->foreign_currency_id) {
            $rateLcFc = $manualExchangeRate ?? $this->rateOnOrBefore($company, $company->foreign_currency_id, $date);
            $foreign = $line->amount();
            $local = $this->money(bcmul($foreign, $rateLcFc, 10));
        } else {
            throw new MissingExchangeRateException(
                'La moneda de la línea debe ser la local o la extranjera configurada en la compañía.'
            );
        }

        $rateFcSc = $company->foreign_currency_id === $company->system_currency_id
            ? '1.000000'
            : $this->rateOnOrBefore($company, $company->system_currency_id, $date);

        $system = $this->money(bcmul($foreign, $rateFcSc, 10));

        $isDebit = $line->isDebit();

        return [
            'currency_id' => $line->currencyId,
            'exchange_rate_lc_fc' => $rateLcFc,
            'exchange_rate_fc_sc' => $rateFcSc,
            'debit_local' => $isDebit ? $local : '0.00',
            'credit_local' => $isDebit ? '0.00' : $local,
            'debit_foreign' => $isDebit ? $foreign : '0.00',
            'credit_foreign' => $isDebit ? '0.00' : $foreign,
            'debit_system' => $isDebit ? $system : '0.00',
            'credit_system' => $isDebit ? '0.00' : $system,
        ];
    }

    private function rateOnOrBefore(Company $company, ?int $currencyId, \DateTimeInterface $date): string
    {
        if (! $currencyId) {
            throw new MissingExchangeRateException('La compañía no tiene configurada esa moneda.');
        }

        $rate = ExchangeRate::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('currency_id', $currencyId)
            ->whereDate('rate_date', '<=', $date->format('Y-m-d'))
            ->orderByDesc('rate_date')
            ->first();

        if (! $rate) {
            throw new MissingExchangeRateException(
                "No hay tipo de cambio registrado para la moneda id {$currencyId} en o antes de {$date->format('Y-m-d')}."
            );
        }

        return (string) $rate->rate;
    }

    private function nextDocumentNumber(DocumentType $documentType): int
    {
        $locked = DocumentType::withoutGlobalScope(CompanyScope::class)
            ->whereKey($documentType->id)
            ->lockForUpdate()
            ->first();

        $number = (int) $locked->next_consecutive;
        $locked->increment('next_consecutive');

        return $number;
    }

    /**
     * Numeración manual por serie: independiente del consecutivo interno,
     * el usuario elige a propósito qué serie usar. Mismo patrón de lock
     * pesimista que nextDocumentNumber(), para que dos posteos concurrentes
     * contra la misma serie nunca repitan número.
     */
    private function nextSeriesNumber(Company $company, DocumentType $documentType, int $numberSeriesId): int
    {
        $series = DocumentTypeNumberSeries::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('document_type_id', $documentType->id)
            ->whereKey($numberSeriesId)
            ->lockForUpdate()
            ->first();

        if (! $series) {
            throw new NumberSeriesExhaustedException(
                "La serie de numeración id {$numberSeriesId} no existe para el tipo de documento {$documentType->code}."
            );
        }

        if (! $series->is_active) {
            throw new NumberSeriesExhaustedException("La serie \"{$series->name}\" está inactiva.");
        }

        if ($series->next_number > $series->range_to) {
            throw new NumberSeriesExhaustedException(
                "La serie \"{$series->name}\" agotó su rango ({$series->range_from}-{$series->range_to})."
            );
        }

        $number = (int) $series->next_number;
        $series->increment('next_number');

        return $number;
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Una línea con opensItem=true (típicamente FVE, NCC) queda como partida
     * pendiente de aplicar en CxC/CxP. original_amount/currency_id se guardan
     * en la moneda "natural" en que se digitó la línea, no en la triple
     * conversión. El vencimiento se lee de $detail (ya resuelto con el
     * fallback de encabezado, ver el bucle que llama a esto), no de
     * $line->dueDate directo — si no, una partida abierta perdería el
     * vencimiento precargado desde el encabezado cuando la línea no trae uno propio.
     */
    private function openBpOpenItem(
        JournalEntry $journalEntry,
        DocumentType $documentType,
        JournalDetail $detail,
        JournalLineInput $line,
    ): void {
        BpOpenItem::create([
            'business_partner_id' => $line->businessPartnerId,
            'origin_journal_detail_id' => $detail->id,
            'document_type_code' => $documentType->code,
            'document_number' => $journalEntry->document_number,
            'due_date' => $detail->due_date,
            'original_amount' => $line->amount(),
            'currency_id' => $line->currencyId,
            'applied_amount' => '0.00',
            'balance' => $line->amount(),
            'status' => 'open',
        ]);
    }

    /**
     * Una línea con applyToOpenItemId cancela (total o parcialmente) una
     * partida existente de OTRO documento — a diferencia de openBpOpenItem()
     * (que ABRE una partida nueva), esta llama a ApplyPaymentService::apply()
     * dentro de la MISMA transacción de post() (Laravel anida vía SAVEPOINT):
     * así un pago nunca queda contabilizado sin aplicarse, ni viceversa — a
     * diferencia del flujo de OpenItemController::applyPayment(), que hace
     * ambos pasos por separado y fuera de este service.
     *
     * El monto aplicado es el propio monto (débito o crédito) de la línea:
     * no tiene sentido que "cuánto pago" y "cuánto contabilizo" difieran
     * dentro del mismo asiento.
     */
    private function applyToExistingOpenItem(
        Company $company,
        JournalEntry $journalEntry,
        JournalLineInput $line,
        \DateTimeInterface $postingDate,
        ?int $createdBy,
    ): void {
        // No se usa el CompanyScope ambiental de BusinessPartner (ver
        // docstring de la clase): este service debe ser correcto incluso sin
        // CurrentCompany seteado.
        $openItem = BpOpenItem::with(['businessPartner' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])
            ->find($line->applyToOpenItemId);

        if (! $openItem || ! $openItem->businessPartner || $openItem->businessPartner->company_id !== $company->id) {
            throw new InvalidOpenItemException("La partida id {$line->applyToOpenItemId} no existe en esta compañía.");
        }

        if ($openItem->business_partner_id !== $line->businessPartnerId) {
            throw new InvalidOpenItemException("La partida #{$openItem->id} no pertenece al socio de negocio de esta línea.");
        }

        $this->applyPaymentService->apply(
            $openItem,
            $journalEntry,
            $line->amount(),
            $postingDate,
            createdBy: $createdBy,
        );
    }

    /**
     * Valida que el monto digitado en la línea coincida con base*tarifa antes
     * de guardar journal_detail_taxes: evita que un reporte de IVA quede
     * armado con datos que ni siquiera cuadran con la tarifa configurada.
     * También exige que la tarifa esté vigente en la fecha de contabilización
     * (postingDate, la rectora — no la del documento fuente) — de lo
     * contrario "cerrar vigencia" una tarifa vieja (ver TaxRate::
     * isEffectiveOn()) sería puramente cosmético y no evitaría seguir
     * contabilizando contra un porcentaje que ya no aplica.
     */
    private function attachTax(JournalDetail $detail, JournalLineInput $line, \DateTimeInterface $postingDate): void
    {
        $taxRate = TaxRate::findOrFail($line->taxRateId);

        if (! $taxRate->isEffectiveOn($postingDate)) {
            throw new TaxRateNotEffectiveException(
                "El indicador {$taxRate->code} ({$taxRate->percentage}%) no está vigente para la fecha {$postingDate->format('Y-m-d')}."
            );
        }

        $expected = $this->money(bcdiv(bcmul($line->taxableBase, (string) $taxRate->percentage, 10), '100', 10));

        if (bccomp($expected, $line->amount(), 2) !== 0) {
            throw new InvalidTaxAmountException(
                "El monto de impuesto de la línea ({$line->amount()}) no corresponde al indicador {$taxRate->percentage}% ".
                "sobre la base {$line->taxableBase} (esperado {$expected})."
            );
        }

        JournalDetailTax::create([
            'journal_detail_id' => $detail->id,
            'tax_rate_id' => $taxRate->id,
            'taxable_base' => $line->taxableBase,
            'tax_amount' => $line->amount(),
        ]);
    }
}
