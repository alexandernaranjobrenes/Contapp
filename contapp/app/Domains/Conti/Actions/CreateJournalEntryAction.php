<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Resources\AccountingResources;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Models\User;

/**
 * Un asiento manual, como el formulario de Contabilidad → Registros: como
 * preliminar (por defecto) o contabilizado. Pasa por PostJournalService, que
 * es el que exige partida doble, período abierto, tipo de cambio, cuentas
 * hoja, socio y norma de reparto donde la cuenta los pide.
 *
 * Lo que no cubre (IVA automático por indicador, aplicar a una partida
 * existente, series manuales, programar) se hace en el formulario.
 */
final class CreateJournalEntryAction extends BaseContiAction
{
    public function key(): string
    {
        return 'crear_asiento';
    }

    public function label(): string
    {
        return 'Registrar un asiento contable';
    }

    public function description(): string
    {
        return 'Registra un asiento manual, como preliminar (por defecto: queda para revisar y contabilizar en Registros) o contabilizado. '
            .'Cada línea va contra una cuenta o contra un socio (que usa su cuenta de control). Débitos y créditos tienen que cuadrar.';
    }

    public function screen(): string
    {
        return 'accounting.journal_entries';
    }

    public function fields(): array
    {
        return [
            'tipo_documento' => 'código del tipo de documento (no los reservados APE, ARR ni ACC)',
            'fecha_contabilizacion' => 'AAAA-MM-DD; por defecto hoy. Fija el período y el tipo de cambio',
            'fecha_documento' => 'AAAA-MM-DD, la del comprobante; por defecto la de contabilización',
            'descripcion' => 'descripción del asiento',
            'modo' => 'preliminar (por defecto) | contabilizar',
            'tipo_cambio' => 'opcional, solo al contabilizar: rige solo para este asiento',
            'lineas' => 'lista de líneas: {cuenta: código | socio: código, debito, credito, moneda (CRC|USD, por defecto la local), '
                .'descripcion, norma_reparto: código, vencimiento: AAAA-MM-DD (abre partida del socio), documento_referencia, clave_electronica (50 dígitos)}',
        ];
    }

    public function form(Company $company): array
    {
        $currencies = $this->currencyOptions($company);

        return [
            $this->field('tipo_documento', 'Tipo de documento', 'buscar', ['requerido' => true, 'fuente' => 'tipos-documento']),
            $this->field('fecha_contabilizacion', 'Fecha de contabilización', 'fecha', ['requerido' => true, 'defecto' => 'hoy', 'ayuda' => 'Fija el período y el tipo de cambio.']),
            $this->field('descripcion', 'Descripción', 'texto'),
            $this->field('modo', 'Al confirmar', 'opciones', [
                'opciones' => ['preliminar' => 'Dejarlo preliminar (se revisa antes de contabilizar)', 'contabilizar' => 'Contabilizarlo'],
                'defecto' => 'preliminar',
            ]),
            $this->field('lineas', 'Líneas', 'lineas', ['requerido' => true, 'minimo' => 2, 'columnas' => [
                $this->field('cuenta', 'Cuenta', 'buscar', ['fuente' => 'cuentas', 'filtros' => ['acepta_movimientos' => 'sí']]),
                $this->field('socio', 'O un socio', 'buscar', ['fuente' => 'socios', 'ayuda' => 'Para las cuentas que exigen socio (CxC, CxP).']),
                $this->field('debito', 'Débito', 'monto'),
                $this->field('credito', 'Crédito', 'monto'),
                $this->field('descripcion', 'Descripción', 'texto'),
                $this->field('moneda', 'Moneda', 'opciones', ['opciones' => $currencies, 'avanzado' => true]),
                $this->field('norma_reparto', 'Norma de reparto', 'buscar', ['fuente' => 'normas-reparto', 'avanzado' => true]),
                $this->field('vencimiento', 'Vencimiento', 'fecha', ['avanzado' => true, 'ayuda' => 'Con socio: abre una partida que vence ese día.']),
                $this->field('documento_referencia', 'Documento de referencia', 'texto', ['avanzado' => true]),
                $this->field('clave_electronica', 'Clave electrónica (50 dígitos)', 'texto', ['avanzado' => true]),
            ]]),
            $this->field('fecha_documento', 'Fecha del documento', 'fecha', ['avanzado' => true, 'ayuda' => 'Vacía: la de contabilización.']),
            $this->field('tipo_cambio', 'Tipo de cambio', 'numero', ['avanzado' => true, 'ayuda' => 'Solo al contabilizar, y solo para este asiento.']),
        ];
    }

    /** El tipo de documento que más se usa en los asientos manuales. */
    public function suggest(array $values, Company $company): array
    {
        if ($this->given($values, 'tipo_documento')) {
            return [];
        }

        $usable = DocumentType::where('status', 'active')->where('generates_journal', true)
            ->where('is_opening_type', false)->where('is_reconciliation_type', false)->where('is_closing_type', false);
        $common = $this->mostCommon(JournalEntry::where('source_module', 'contable')->whereIn('document_type_id', (clone $usable)->pluck('id')), 'document_type_id');

        if ($common !== null) {
            return ['tipo_documento' => $this->suggestion(DocumentType::find($common[0])?->code, "Es el que más se usa en los asientos manuales ({$common[1]} de {$common[2]}).")];
        }

        $type = (clone $usable)->where('origin_module', 'contable')->orderBy('code')->first();

        return $type ? ['tipo_documento' => $this->suggestion($type->code, "Es el tipo de documento contable de la compañía: {$type->name}.")] : [];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $input['fecha_contabilizacion'] = ($input['fecha_contabilizacion'] ?? '') !== '' ? $input['fecha_contabilizacion'] : now()->format('Y-m-d');
        $input['fecha_documento'] = ($input['fecha_documento'] ?? '') !== '' ? $input['fecha_documento'] : $input['fecha_contabilizacion'];
        $input['modo'] = ($input['modo'] ?? '') !== '' ? mb_strtolower($input['modo']) : 'preliminar';

        $this->validate($input, [
            'tipo_documento' => ['required', 'string'],
            'fecha_contabilizacion' => ['required', 'date'],
            'fecha_documento' => ['required', 'date'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'modo' => ['required', 'in:preliminar,contabilizar'],
            'tipo_cambio' => ['nullable', 'numeric', 'gt:0'],
            'lineas' => ['required', 'array', 'min:2', 'max:200'],
            'lineas.*.cuenta' => ['nullable', 'string'],
            'lineas.*.socio' => ['nullable', 'string'],
            'lineas.*.debito' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.credito' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.moneda' => ['nullable', 'string', 'max:3'],
            'lineas.*.descripcion' => ['nullable', 'string', 'max:255'],
            'lineas.*.norma_reparto' => ['nullable', 'string'],
            'lineas.*.vencimiento' => ['nullable', 'date'],
            'lineas.*.documento_referencia' => ['nullable', 'string', 'max:255'],
            'lineas.*.clave_electronica' => ['nullable', 'digits:50'],
        ]);

        $post = $input['modo'] === 'contabilizar';

        $type = $this->byCode(DocumentType::class, $input['tipo_documento'], 'tipo_documento', 'un tipo de documento');

        if ($type->status !== 'active' || ! $type->generates_journal || $type->is_opening_type || $type->is_reconciliation_type || $type->is_closing_type) {
            $this->fail('tipo_documento', "El tipo {$type->code} no se usa en asientos manuales (está inactivo, no genera asiento o es un tipo reservado del sistema).");
        }

        $lines = [];
        $rows = [];
        $keys = [];
        $totals = [];

        foreach (array_values($input['lineas']) as $i => $line) {
            $n = $i + 1;
            $field = "lineas.{$i}";
            $debit = bcadd((string) ($line['debito'] ?? '0'), '0', 2);
            $credit = bcadd((string) ($line['credito'] ?? '0'), '0', 2);

            if ((bccomp($debit, '0', 2) > 0) === (bccomp($credit, '0', 2) > 0)) {
                $this->fail($field, "La línea {$n} tiene que llevar un monto en débito o en crédito (uno solo, mayor que cero).");
            }

            $partner = null;

            if (($line['socio'] ?? '') !== '') {
                $partner = $this->byCode(BusinessPartner::class, $line['socio'], "{$field}.socio", 'un socio');
                $account = ChartOfAccount::find($partner->gl_account_id) ?? $this->fail("{$field}.socio", "El socio {$partner->code} no tiene cuenta de control.");
            } elseif (($line['cuenta'] ?? '') !== '') {
                $account = $this->byCode(ChartOfAccount::class, $line['cuenta'], "{$field}.cuenta", 'una cuenta');

                if ($account->requires_business_partner) {
                    $this->fail("{$field}.cuenta", "La cuenta {$account->code} exige socio de negocio: en la línea {$n} mandá «socio» con el código del cliente o proveedor en vez de «cuenta».");
                }
            } else {
                $this->fail($field, "La línea {$n} necesita «cuenta» o «socio».");
            }

            if (! $account->accepts_posting) {
                $this->fail($field, "La cuenta {$account->code} no acepta movimientos: no es cuenta hoja.");
            }

            $rule = $this->optionalByCode(CostAllocationRule::class, $line['norma_reparto'] ?? null, "{$field}.norma_reparto", 'una norma de reparto');

            if ($account->requires_cost_center && $rule === null && $post) {
                $this->fail("{$field}.norma_reparto", "La cuenta {$account->code} exige una norma de reparto en la línea {$n}.");
            }

            $currency = $this->currency($company, $line['moneda'] ?? null, "{$field}.moneda");
            $dueDate = ($line['vencimiento'] ?? '') !== '' ? date('Y-m-d', strtotime($line['vencimiento'])) : null;
            $key = ($line['clave_electronica'] ?? '') !== '' ? (string) $line['clave_electronica'] : null;

            if ($key !== null) {
                if (in_array($key, $keys, true)) {
                    $this->fail("{$field}.clave_electronica", 'Dos líneas del mismo asiento no pueden tener la misma clave numérica electrónica.');
                }

                $taken = JournalDetail::whereHas('journalEntry', fn ($q) => $q->withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id))
                    ->where('electronic_key', $key)->exists();

                if ($taken) {
                    $this->fail("{$field}.clave_electronica", 'Esta clave numérica ya está registrada en otro asiento de esta compañía.');
                }

                $keys[] = $key;
            }

            $totals[$currency->code]['debito'] = bcadd($totals[$currency->code]['debito'] ?? '0', $debit, 2);
            $totals[$currency->code]['credito'] = bcadd($totals[$currency->code]['credito'] ?? '0', $credit, 2);

            $lines[] = [
                'account_id' => $account->id,
                'currency_id' => $currency->id,
                'debit' => $debit,
                'credit' => $credit,
                'description' => ($line['descripcion'] ?? '') !== '' ? $line['descripcion'] : null,
                'business_partner_id' => $partner?->id,
                'cost_allocation_rule_id' => $rule?->id,
                'due_date' => $partner ? $dueDate : null,
                'opens_item' => $partner !== null && $dueDate !== null,
                'reference_document' => ($line['documento_referencia'] ?? '') !== '' ? $line['documento_referencia'] : null,
                'electronic_key' => $key,
            ];

            $rows[] = [
                $partner ? "Socio {$partner->code} {$partner->name} ({$account->code})" : "{$account->code} {$account->description_es}",
                $currency->code,
                bccomp($debit, '0', 2) > 0 ? $this->money($debit) : null,
                bccomp($credit, '0', 2) > 0 ? $this->money($credit) : null,
                trim(($line['descripcion'] ?? '').($rule ? " · norma {$rule->code}" : '').($dueDate && $partner ? " · vence {$dueDate}" : '')) ?: null,
            ];
        }

        if ($type->requires_electronic_key && $post && $keys === []) {
            $this->fail('lineas', "El tipo {$type->code} exige la clave numérica electrónica en al menos una línea.");
        }

        // Con una sola moneda, el cuadre se revisa acá; con dos, lo revisa el
        // servicio en las tres monedas al convertir.
        if (count($totals) === 1) {
            $only = reset($totals);

            if (bccomp($only['debito'], $only['credito'], 2) !== 0) {
                $this->fail('lineas', "El asiento no cuadra: débitos {$this->money($only['debito'])} contra créditos {$this->money($only['credito'])}.");
            }
        }

        $items = $this->items([
            'Tipo de documento' => "{$type->code} {$type->name}",
            'Se guarda como' => $post ? 'Contabilizado' : 'Preliminar (queda para revisar y contabilizar en Registros)',
            'Fecha de contabilización' => date('Y-m-d', strtotime($input['fecha_contabilizacion'])),
            'Fecha de documento' => date('Y-m-d', strtotime($input['fecha_documento'])),
            'Descripción' => $input['descripcion'] ?? null,
            'Tipo de cambio' => $post && ($input['tipo_cambio'] ?? '') !== '' ? (string) $input['tipo_cambio'].' (solo para este asiento)' : null,
        ]);

        foreach ($totals as $code => $total) {
            $items[] = $this->item("Total {$code}", "débitos {$this->money($total['debito'])} · créditos {$this->money($total['credito'])}");
        }

        return new PreparedAction(
            title: ($post ? 'Contabilizar' : 'Guardar como preliminar').' un asiento '.$type->code,
            items: $items,
            payload: [
                'document_type_id' => $type->id,
                'document_date' => date('Y-m-d', strtotime($input['fecha_documento'])),
                'posting_date' => date('Y-m-d', strtotime($input['fecha_contabilizacion'])),
                'description' => ($input['descripcion'] ?? '') !== '' ? $input['descripcion'] : null,
                'post' => $post,
                'exchange_rate' => $post && ($input['tipo_cambio'] ?? '') !== '' ? (string) $input['tipo_cambio'] : null,
                'lines' => $lines,
            ],
            input: $input,
            table: ['columnas' => ['Cuenta o socio', 'Moneda', 'Débito', 'Crédito', 'Detalle'], 'filas' => $rows],
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        $p = $prepared->payload;
        $type = DocumentType::findOrFail($p['document_type_id']);
        $service = app(PostJournalService::class);

        $lines = array_map(fn (array $l) => new JournalLineInput(
            accountId: $l['account_id'],
            currencyId: $l['currency_id'],
            debit: $l['debit'],
            credit: $l['credit'],
            description: $l['description'],
            businessPartnerId: $l['business_partner_id'],
            costAllocationRuleId: $l['cost_allocation_rule_id'],
            dueDate: $l['due_date'],
            referenceDocument: $l['reference_document'],
            opensItem: $l['opens_item'],
            allowZeroAmount: ! $p['post'],
            electronicKey: $l['electronic_key'],
        ), $p['lines']);

        if ($p['post']) {
            $entry = $service->post(
                $company, $type, new \DateTime($p['document_date']), new \DateTime($p['posting_date']), $lines,
                $p['description'], $user->id,
                manualExchangeRate: $p['exchange_rate'],
            );
        } else {
            $entry = $service->saveDraft(
                $company, $type, new \DateTime($p['document_date']), new \DateTime($p['posting_date']), $lines,
                $p['description'], $user->id,
            );
        }

        $entry->loadMissing('documentType:id,code');
        $label = AccountingResources::entryLabel($entry);

        return new ActionResult(
            $p['post'] ? "Asiento {$label} contabilizado." : "Asiento {$label} guardado como preliminar: revisalo y contabilizalo en Registros.",
            route('journal-entries.show', $entry->id),
            'Ver el asiento',
        );
    }
}
