<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\Core\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recorre TODA la compañía (todos los socios de negocio, no uno a la vez) en
 * busca de líneas ya contabilizadas con socio de negocio que nunca abrieron
 * partida — mismo problema que resuelve JournalEntryController::updateLineDueDate()
 * para una línea puntual, pero acá de una sola pasada. El asiento original
 * nunca se toca (cuenta, monto, socio siguen intocables); solo se completa
 * el registro de bp_open_items que le faltaba a cada línea.
 *
 * Vencimiento de cada partida nueva, en orden de preferencia:
 *  1. El due_date que la línea ya tenía (si lo tenía, pero por algún motivo
 *     nunca llegó a crear su BpOpenItem).
 *  2. Días de crédito del socio (payment_terms_days) + la fecha del
 *     documento de ESA línea (reference_document_date), o si la línea no
 *     tiene la suya, la fecha de documento del encabezado del asiento.
 *  3. Si el socio no tiene días de crédito configurados y la línea no tenía
 *     vencimiento propio, no hay forma automática de determinarlo — esa
 *     línea se reporta como omitida para corregirla a mano (ver
 *     JournalEntries/Show.vue, botón "abrir partida").
 */
class OpenItemBackfillService
{
    public function __construct(private readonly RetroactiveOpenItemService $opener) {}

    /**
     * @return array{
     *     created: array<int, array{detail_id: int, partner_code: string, partner_name: string, amount: string, due_date: string}>,
     *     skipped: array<int, array{detail_id: int, partner_code: string, reason: string}>,
     * }
     */
    public function run(Company $company, bool $dryRun = false): array
    {
        $details = JournalDetail::whereHas('journalEntry', function ($q) use ($company) {
            $q->where('company_id', $company->id)->where('status', 'posted');
        })
            ->whereNotNull('business_partner_id')
            ->with([
                'businessPartner:id,code,name,payment_terms_days',
                'journalEntry:id,document_type_id,document_number,document_date',
                'journalEntry.documentType:id,code',
            ])
            ->get();

        if ($details->isEmpty()) {
            return ['created' => [], 'skipped' => []];
        }

        $existingDetailIds = BpOpenItem::whereIn('origin_journal_detail_id', $details->pluck('id'))
            ->where('status', '!=', 'closed')
            ->pluck('origin_journal_detail_id');

        $missing = $details->reject(fn (JournalDetail $d) => $existingDetailIds->contains($d->id));

        $created = [];
        $skipped = [];

        foreach ($missing as $detail) {
            $partner = $detail->businessPartner;

            $amount = bccomp($detail->debit_local, '0.00', 2) > 0 ? $detail->debit_local : $detail->credit_local;

            if (bccomp($amount, '0.00', 2) === 0) {
                $skipped[] = ['detail_id' => $detail->id, 'partner_code' => $partner->code, 'reason' => 'El monto de la línea es cero.'];

                continue;
            }

            $dueDate = $detail->due_date?->format('Y-m-d');

            if ($dueDate === null) {
                if (! $partner->payment_terms_days) {
                    $skipped[] = [
                        'detail_id' => $detail->id,
                        'partner_code' => $partner->code,
                        'reason' => 'Sin vencimiento propio y el socio no tiene días de crédito configurados — hay que definirlo a mano.',
                    ];

                    continue;
                }

                $baseDate = $detail->reference_document_date?->format('Y-m-d') ?? $detail->journalEntry->document_date->format('Y-m-d');
                $dueDate = Carbon::parse($baseDate)->addDays((int) $partner->payment_terms_days)->format('Y-m-d');
            }

            if (! $dryRun) {
                $opener = $this->opener;

                DB::transaction(function () use ($detail, $dueDate, $opener) {
                    if ($detail->due_date === null) {
                        $detail->update(['due_date' => $dueDate]);
                    }

                    $opener->open(
                        $detail,
                        $detail->journalEntry->documentType->code,
                        $detail->journalEntry->document_number,
                        $dueDate,
                    );
                });
            }

            $created[] = [
                'detail_id' => $detail->id,
                'partner_code' => $partner->code,
                'partner_name' => $partner->name,
                'amount' => $amount,
                'due_date' => $dueDate,
            ];
        }

        return compact('created', 'skipped');
    }
}
