<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\InventoryAgingExporter;
use App\Domains\Inventory\Services\InventoryAgingService;
use App\Domains\Reporting\Support\ReportHeaderFactory;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryAgingController extends Controller
{
    private const TITLE = 'Antigüedad de inventario';

    public function index(Request $request, CurrentCompany $currentCompany, InventoryAgingService $service): InertiaResponse
    {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        return Inertia::render('Reports/InventoryAging', [
            'filters' => $filters,
            'result' => $this->build($service, $company, $filters),
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'itemGroups' => ItemGroup::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function export(
        Request $request,
        CurrentCompany $currentCompany,
        InventoryAgingService $service,
        InventoryAgingExporter $exporter,
        ReportHeaderFactory $headerFactory,
    ): StreamedResponse {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $this->build($service, $company, $filters);
        $header = $headerFactory->make($company, $request->user(), self::TITLE, $this->paramsSummary($filters));

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $header, $result),
            'antiguedad-inventario.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function exportPdf(
        Request $request,
        CurrentCompany $currentCompany,
        InventoryAgingService $service,
        ReportHeaderFactory $headerFactory,
    ): Response {
        $filters = $this->validateFilters($request);
        $company = Company::findOrFail($currentCompany->id());

        $result = $this->build($service, $company, $filters);
        $header = $headerFactory->make($company, $request->user(), self::TITLE, $this->paramsSummary($filters));

        return Pdf::loadView('reports.inventory-aging', compact('header', 'result'))
            ->setPaper('letter', 'landscape')
            ->download('antiguedad-inventario.pdf');
    }

    private function build(InventoryAgingService $service, Company $company, array $filters)
    {
        return $service->build(
            $company,
            $filters['as_of'],
            $filters['buckets'],
            $filters['warehouse_id'],
            $filters['item_group_id'],
        );
    }

    private function validateFilters(Request $request): array
    {
        $companyId = app(CurrentCompany::class)->id();

        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            // Sin regex: DayBucketScheme ya cae a su default ante cualquier
            // entrada mal formada, y un reporte no debe reventar por un
            // filtro opcional.
            'buckets' => ['nullable', 'string', 'max:60'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'item_group_id' => ['nullable', 'integer', Rule::exists('item_groups', 'id')->where('company_id', $companyId)],
        ]);

        return [
            'as_of' => $validated['as_of'] ?? now()->format('Y-m-d'),
            'buckets' => $validated['buckets'] ?? null,
            'warehouse_id' => isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            'item_group_id' => isset($validated['item_group_id']) ? (int) $validated['item_group_id'] : null,
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

        $parts[] = 'Antigüedad medida desde la última salida';

        return implode(' · ', $parts);
    }
}
