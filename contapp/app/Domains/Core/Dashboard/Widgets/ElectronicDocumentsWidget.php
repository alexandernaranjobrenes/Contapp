<?php

namespace App\Domains\Core\Dashboard\Widgets;

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Core\Dashboard\DashboardContext;
use App\Domains\Core\Dashboard\DashboardWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los comprobantes electrónicos que Hacienda rechazó, no recibió o que
 * requieren atención: los que hay que corregir y volver a emitir. Solo con
 * la facturación electrónica encendida en la compañía.
 */
class ElectronicDocumentsWidget extends DashboardWidget
{
    private const ATTENTION = [
        ElectronicDocument::REJECTED,
        ElectronicDocument::SUBMIT_FAILED,
        ElectronicDocument::NEEDS_ATTENTION,
        ElectronicDocument::SUBMIT_UNKNOWN,
    ];

    private const BADGES = [
        ElectronicDocument::REJECTED => 'badge-danger',
        ElectronicDocument::SUBMIT_FAILED => 'badge-danger',
        ElectronicDocument::NEEDS_ATTENTION => 'badge-warning',
        ElectronicDocument::SUBMIT_UNKNOWN => 'badge-warning',
    ];

    public function key(): string
    {
        return 'einvoice_attention';
    }

    public function title(): string
    {
        return 'Comprobantes por revisar';
    }

    public function description(): string
    {
        return 'Los comprobantes electrónicos que Hacienda rechazó o que requieren atención. Solo con la facturación electrónica encendida.';
    }

    public function section(): string
    {
        return 'billing';
    }

    public function kind(): string
    {
        return 'list';
    }

    public function screens(): array
    {
        return ['billing.sales_documents'];
    }

    public function requires(): array
    {
        return ['electronic_invoicing'];
    }

    public function href(DashboardContext $context): ?string
    {
        return route('sales-documents.index');
    }

    public function data(DashboardContext $context): array
    {
        $documents = DB::table('electronic_documents')
            ->leftJoin('sales_documents', 'sales_documents.id', '=', 'electronic_documents.sales_document_id')
            ->leftJoin('business_partners', 'business_partners.id', '=', 'sales_documents.business_partner_id')
            ->where('electronic_documents.company_id', $context->company->id)
            ->whereIn('electronic_documents.status', self::ATTENTION);

        $count = (clone $documents)->count();

        $rows = $documents
            ->orderByDesc('electronic_documents.issued_at')
            ->orderByDesc('electronic_documents.id')
            ->take(5)
            ->get([
                'electronic_documents.status', 'electronic_documents.consecutive', 'electronic_documents.issued_at',
                'electronic_documents.hacienda_message', 'electronic_documents.sales_document_id', 'business_partners.name as customer',
            ]);

        return [
            'count' => $count,
            'rows' => $rows->map(fn ($row) => [
                'title' => $row->consecutive ?: 'Sin consecutivo',
                'subtitle' => $row->customer ?? 'Consumidor final',
                'meta' => $row->issued_at ? Carbon::parse($row->issued_at)->timezone('America/Costa_Rica')->format('d/m/Y') : null,
                'badge' => ['label' => ElectronicDocument::LABELS[$row->status] ?? $row->status, 'badge' => self::BADGES[$row->status] ?? 'badge-neutral'],
                'href' => $row->sales_document_id ? route('sales-documents.show', $row->sales_document_id) : null,
            ])->all(),
            'more' => max(0, $count - $rows->count()),
            'empty' => 'Todo al día: ningún comprobante requiere atención.',
            'emptyGood' => true,
        ];
    }
}
