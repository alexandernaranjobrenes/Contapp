<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Support\CostaRicaTerritory;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Billing\Support\FiscalDecimal;
use App\Domains\Billing\Support\HaciendaClock;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\MediaStorage;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * La representación gráfica del comprobante: el PDF que se le entrega al
 * cliente junto con el XML firmado y la respuesta de Hacienda.
 *
 * Sale de lo mismo que el XML (el comprobante guardado), no de datos propios:
 * si el PDF dijera otra cosa que el XML, el que vale es el XML, y el cliente
 * tendría en la mano un papel que no coincide con lo que Hacienda aceptó.
 *
 * El código QR no va: la resolución de la v4.4 lo dejó suspendido hasta que
 * Hacienda avise otra cosa.
 */
class ElectronicDocumentPdf
{
    public function __construct(
        private readonly MediaStorage $media,
        private readonly ElectronicInvoicing $electronic,
    ) {}

    public function pdf(Company $company, SalesDocument $document): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('reports.electronic-document', $this->payload($company, $document))
            ->setPaper('letter', 'portrait');
    }

    public function filename(SalesDocument $document): string
    {
        return ($document->consecutive ?: 'comprobante-'.$document->id).'.pdf';
    }

    /** @return array<string, mixed> */
    public function payload(Company $company, SalesDocument $document): array
    {
        $document->loadMissing(['lines.taxes', 'payments', 'references', 'businessPartner', 'currency']);
        $settings = $this->electronic->settings($company);

        $electronic = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('sales_document_id', $document->id)
            ->orderByDesc('id')
            ->first();

        $partner = $document->businessPartner;
        $currency = $document->currency?->code ?? 'CRC';
        $money = fn ($value) => number_format((float) FiscalDecimal::round((string) $value, 2), 2, ',', '.');

        $breakdown = [];

        foreach ($document->lines as $line) {
            foreach ($line->taxes as $tax) {
                $key = $tax->tax_code.'|'.$tax->iva_rate_code;
                $breakdown[$key] ??= [
                    'label' => $tax->iva_rate_code
                        ? (FiscalCatalogs::IVA_RATES[$tax->iva_rate_code]['label'] ?? $tax->iva_rate_code)
                        : (FiscalCatalogs::TAX_CODES[$tax->tax_code] ?? $tax->tax_code),
                    'amount' => '0',
                ];
                $breakdown[$key]['amount'] = bcadd($breakdown[$key]['amount'], (string) $tax->net_amount, 5);
            }
        }

        $location = null;

        if ($settings?->province) {
            // Del más general al más particular: «Cartago, Cartago, San
            // Nicolás, Barrio…, otras señas».
            $location = trim(implode(', ', array_filter([
                ...CostaRicaTerritory::names($settings->province, $settings->canton, $settings->district),
                $settings->neighborhood,
                $settings->address_details,
            ])));
        }

        return [
            'money' => $money,
            'currency' => $currency,
            'company' => [
                'name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'tax_id' => $company->tax_id,
                'identification' => FiscalCatalogs::IDENTIFICATION_TYPES[$settings?->identification_type ?? ''] ?? 'Cédula',
                'location' => $location ?: $company->address,
                'phone' => $settings?->phone,
                'emails' => $settings?->emailList() ?? [],
                'logo_data_uri' => $this->media->dataUri($company->logo_path),
            ],
            'document' => [
                'type' => FiscalCatalogs::DOCUMENT_TYPES[$document->fiscal_document_type] ?? 'Comprobante',
                'consecutive' => $document->consecutive,
                'clave' => $document->clave,
                'issued_at' => $document->issued_at
                    ? HaciendaClock::local($document->issued_at)->format('d/m/Y h:i a')
                    : $document->document_date->format('d/m/Y'),
                'sale_condition' => FiscalCatalogs::SALE_CONDITIONS[$document->sale_condition] ?? $document->sale_condition,
                'sale_condition_other' => $document->sale_condition_other,
                'credit_term_days' => $document->credit_term_days,
                'due_date' => $document->due_date?->format('d/m/Y'),
                'activity' => $document->emitter_activity_code,
                'exchange_rate' => $currency === 'CRC' ? null : number_format((float) $document->exchange_rate, 5, ',', '.'),
                'notes' => $document->notes,
            ],
            'receiver' => $partner ? [
                'name' => $partner->name,
                'identification' => $partner->identification_type
                    ? (FiscalCatalogs::IDENTIFICATION_TYPES[$partner->identification_type] ?? '').' '.$partner->tax_id
                    : $partner->tax_id,
                'email' => $partner->email,
                'phone' => $partner->phone,
            ] : null,
            'lines' => $document->lines->map(fn ($line) => [
                'number' => $line->line_number,
                'code' => $line->item_code,
                'cabys' => $line->cabys_code,
                'description' => $line->description,
                'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 3, ',', '.'), '0'), ','),
                'unit' => $line->unit_code,
                'unit_price' => $money($line->unit_price),
                'discount' => FiscalDecimal::isZero((string) $line->discount_amount) ? null : $money($line->discount_amount),
                'tax' => $line->taxes->map(fn ($t) => rtrim(rtrim(number_format((float) $t->rate_percentage, 2, ',', '.'), '0'), ',').'%')->implode(' + '),
                'total' => $money($line->line_total),
            ])->all(),
            'totals' => [
                'Gravado' => $money(bcadd((string) $document->total_taxed_services, (string) $document->total_taxed_goods, 5)),
                'Exento' => $money(bcadd((string) $document->total_exempt_services, (string) $document->total_exempt_goods, 5)),
                'Exonerado' => $money(bcadd((string) $document->total_exonerated_services, (string) $document->total_exonerated_goods, 5)),
                'No sujeto' => $money(bcadd((string) $document->total_no_subject_services, (string) $document->total_no_subject_goods, 5)),
                'Total venta' => $money($document->total_sale),
                'Descuentos' => $money($document->total_discounts),
                'Venta neta' => $money($document->total_net_sale),
            ],
            'breakdown' => array_map(fn (array $b) => ['label' => $b['label'], 'amount' => $money($b['amount'])], array_values($breakdown)),
            'total_tax' => $money($document->total_tax),
            'total' => $money($document->total_document),
            'payments' => $document->payments->map(fn ($p) => [
                'label' => $p->method_code === '99' && $p->method_other ? $p->method_other : (FiscalCatalogs::PAYMENT_METHODS[$p->method_code] ?? $p->method_code),
                'amount' => $money($p->amount),
            ])->all(),
            'references' => $document->references->map(fn ($r) => [
                'type' => FiscalCatalogs::REFERENCE_DOCUMENT_TYPES[$r->document_type] ?? $r->document_type,
                'number' => $r->number,
                'date' => $r->issued_at ? HaciendaClock::local($r->issued_at)->format('d/m/Y') : null,
                'reason' => (FiscalCatalogs::REFERENCE_REASONS[$r->reason_code] ?? $r->reason_code).' — '.$r->reason,
            ])->all(),
            'hacienda' => [
                'status' => $electronic?->label(),
                'accepted' => $electronic?->status === ElectronicDocument::ACCEPTED,
                // Un comprobante del sandbox no vale como factura: se dice en
                // el papel para que nadie lo tome como uno real.
                'test' => $electronic?->environment === 'stag',
                'electronic' => $electronic !== null,
            ],
            'generated_at' => HaciendaClock::local(now())->format('d/m/Y h:i a'),
        ];
    }
}
