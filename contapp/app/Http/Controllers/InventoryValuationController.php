<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\InventoryValuationExporter;
use App\Domains\Inventory\Services\InventoryValuationService;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryValuationController extends Controller
{
    private const TITLE = 'Existencias valorizadas';

    public function index(Request $request, CurrentCompany $currentCompany, InventoryValuationService $service): InertiaResponse
    {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        return Inertia::render('Reports/InventoryValuation', [
            'filters' => $filters,
            'result' => $this->build($service, $company, $filters),
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'itemGroups' => ItemGroup::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        InventoryValuationService $service,
        InventoryValuationExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $this->build($service, $company, $filters);
        $header = $headerFactory->make($company, $request->user(), self::TITLE, $this->paramsSummary($filters));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'existencias-valorizadas.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        InventoryValuationService $service,
        ReportHeaderFactory $headerFactory,
    ): Response {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $this->build($service, $company, $filters);
        $header = $headerFactory->make($company, $request->user(), self::TITLE, $this->paramsSummary($filters));

        return Pdf::loadView('reports.inventory-valuation', compact('header', 'result'))
            ->setPaper('letter', 'landscape')
            ->download('existencias-valorizadas.pdf');
    }

    /**
     * @param  array{as_of: string, warehouse_id: ?int, item_group_id: ?int, hide_zero: bool}  $filters
     */
    private function build(InventoryValuationService $service, Company $company, array $filters)
    {
        return $service->build(
            $company,
            $filters['as_of'],
            $filters['warehouse_id'],
            $filters['item_group_id'],
            $filters['hide_zero'],
        );
    }

    private function validateFilters(Request $request): array
    {
        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'item_group_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('item_groups', 'id')->where('company_id', $companyId)],
            'hide_zero' => ['nullable', 'boolean'],
        ]);

        return [
            // Por defecto hoy: el corte más pedido es "cuánto tengo ahora",
            // y una fecha pasada es el caso de cierre.
            'as_of' => $validated['as_of'] ?? now()->format('Y-m-d'),
            'warehouse_id' => isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            'item_group_id' => isset($validated['item_group_id']) ? (int) $validated['item_group_id'] : null,
            'hide_zero' => filter_var($validated['hide_zero'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function paramsSummary(array $filters): string
    {
        $parts = ['Al '.$filters['as_of']];

        if ($filters['warehouse_id'] !== null) {
            $parts[] = 'Almacén: '.(Warehouse::find($filters['warehouse_id'])?->code ?? $filters['warehouse_id']);
        }

        if ($filters['item_group_id'] !== null) {
            $parts[] = 'Grupo: '.(ItemGroup::find($filters['item_group_id'])?->code ?? $filters['item_group_id']);
        }

        if (! $filters['hide_zero']) {
            $parts[] = 'Incluye existencias en cero';
        }

        return implode(' · ', $parts);
    }
}
