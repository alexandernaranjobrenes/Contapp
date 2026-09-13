<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\DocumentType;

function documentTypeRegisterHttpFixture(): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001',
        'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);
    $fve = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);

    return compact('user', 'company', 'cash', 'sales', 'add', 'fve');
}

it('el índice trae los tipos de documento activos que generan asiento', function () {
    $fx = documentTypeRegisterHttpFixture();

    $this->get(route('reports.document-type-register.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/DocumentTypeRegister')
            ->has('documentTypes', 2)
        );
});

it('exporta sin document_type_id: incluye los asientos de todos los tipos', function () {
    $fx = documentTypeRegisterHttpFixture();

    app(PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime('2026-01-10'), new DateTime('2026-01-10'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '500', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '500'),
        ],
    );
    app(PostJournalService::class)->post(
        $fx['company'], $fx['fve'], new DateTime('2026-01-11'), new DateTime('2026-01-11'),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: '100', credit: 0),
            new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: '100'),
        ],
    );

    $response = $this->get(route('reports.document-type-register.export', ['statuses' => ['posted']]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Content-Disposition'))->toContain('registro-todos-los-documentos.xlsx');
});

it('exporta con document_type_id: nombra el archivo con el código del tipo', function () {
    $fx = documentTypeRegisterHttpFixture();

    $response = $this->get(route('reports.document-type-register.export', [
        'document_type_id' => $fx['add']->id,
        'statuses' => ['posted'],
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain("registro-{$fx['add']->code}.xlsx");
});
