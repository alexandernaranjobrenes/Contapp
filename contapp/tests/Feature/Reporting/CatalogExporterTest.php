<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Services\CatalogExporter;
use App\Domains\Tax\Models\TaxRate;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @return array<int, array{sheet: string, rows: array<int, array<int, mixed>>}>
 */
function readWorkbook(string $path): array
{
    $reader = new Reader();
    $reader->open($path);

    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[] = ['sheet' => $sheet->getName(), 'rows' => $rows];
    }

    $reader->close();
    unlink($path);

    return $sheets;
}

it('genera solo las hojas de los catálogos seleccionados, en el orden pedido', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company);

    CostCenter::factory()->create(['company_id' => $company->id]);

    $path = sys_get_temp_dir().'/catalog-export-test-'.uniqid().'.xlsx';
    app(CatalogExporter::class)->writeTo($path, ['cost-centers', 'tax-rates'], true);

    $sheets = readWorkbook($path);

    expect($sheets)->toHaveCount(2)
        ->and($sheets[0]['sheet'])->toBe('Centros de costo')
        ->and($sheets[1]['sheet'])->toBe('Indicadores de IVA');
});

it('exporta el catálogo de cuentas contables aislado por compañía', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01', 'description_es' => 'CAJA PROPIA']);
    ChartOfAccount::factory()->create(['company_id' => $otherCompany->id, 'code' => '1-02', 'description_es' => 'CAJA AJENA']);

    app(CurrentCompany::class)->set($company);

    $path = sys_get_temp_dir().'/catalog-export-test-'.uniqid().'.xlsx';
    app(CatalogExporter::class)->writeTo($path, ['chart-of-accounts'], true);

    $sheets = readWorkbook($path);
    $descriptions = collect($sheets[0]['rows'])->flatten()->implode('|');

    expect($descriptions)->toContain('CAJA PROPIA')
        ->and($descriptions)->not->toContain('CAJA AJENA');
});

it('excluye centros de costo inactivos por defecto, y los incluye si se pide explícitamente', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company);

    CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-ACTIVO', 'is_active' => true]);
    CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-INACTIVO', 'is_active' => false]);

    $hiddenPath = sys_get_temp_dir().'/catalog-export-test-'.uniqid().'.xlsx';
    app(CatalogExporter::class)->writeTo($hiddenPath, ['cost-centers'], false);
    $hidden = collect(readWorkbook($hiddenPath)[0]['rows'])->flatten()->implode('|');

    $shownPath = sys_get_temp_dir().'/catalog-export-test-'.uniqid().'.xlsx';
    app(CatalogExporter::class)->writeTo($shownPath, ['cost-centers'], true);
    $shown = collect(readWorkbook($shownPath)[0]['rows'])->flatten()->implode('|');

    expect($hidden)->toContain('CC-ACTIVO')->not->toContain('CC-INACTIVO')
        ->and($shown)->toContain('CC-ACTIVO')->toContain('CC-INACTIVO');
});

it('aplana la norma de reparto en una fila por centro de costo, con el porcentaje asignado', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company);

    $centerA = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-A']);
    $centerB = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-B']);
    CostAllocationRule::factory()
        ->withEvenSplit($centerA, $centerB)
        ->create(['company_id' => $company->id, 'code' => 'NORMA-01']);

    $path = sys_get_temp_dir().'/catalog-export-test-'.uniqid().'.xlsx';
    app(CatalogExporter::class)->writeTo($path, ['cost-allocation-rules'], true);

    $rows = readWorkbook($path)[0]['rows'];

    // Encabezado + una fila por cada centro de costo de la norma.
    expect($rows)->toHaveCount(3);
    expect(collect($rows)->flatten()->implode('|'))
        ->toContain('NORMA-01')
        ->toContain('CC-A')
        ->toContain('CC-B');
});

it('el catálogo de indicadores de IVA no se filtra por compañía activa, es catálogo nacional', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company);

    TaxRate::factory()->create(['code' => 'IVA-13']);

    $path = sys_get_temp_dir().'/catalog-export-test-'.uniqid().'.xlsx';
    app(CatalogExporter::class)->writeTo($path, ['tax-rates'], true);

    $rows = readWorkbook($path)[0]['rows'];

    expect(collect($rows)->flatten()->implode('|'))->toContain('IVA-13');
});
