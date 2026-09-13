<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Exceptions\DraftEntriesExistException;
use App\Domains\Accounting\Exceptions\InvalidPeriodTransitionException;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PeriodCloseService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;

function periodCloseFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $sales = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '4-01-01-01-001', 'account_type' => 'income', 'normal_balance' => 'credit',
    ]);
    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '5-01-01-01-001', 'account_type' => 'expense', 'normal_balance' => 'debit',
    ]);
    $retainedEarnings = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '3-02-01-01-001', 'account_type' => 'equity', 'normal_balance' => 'credit',
    ]);

    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);
    $acc = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ACC', 'is_closing_type' => true]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    $jan = FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
    ]);
    $feb = FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 2,
        'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'open',
    ]);

    return compact('company', 'cash', 'sales', 'expense', 'retainedEarnings', 'add', 'acc', 'fiscalYear', 'jan', 'feb');
}

it('closingDocumentType crea el tipo ACC de oficio la primera vez, marcado is_closing_type', function () {
    $company = Company::factory()->create();

    $acc = app(PeriodCloseService::class)->closingDocumentType($company);

    expect($acc->code)->toBe('ACC')
        ->and($acc->is_closing_type)->toBeTrue()
        ->and($acc->generates_journal)->toBeTrue();
});

it('closingDocumentType devuelve el mismo tipo ACC en llamadas siguientes, sin duplicarlo', function () {
    $company = Company::factory()->create();
    $service = app(PeriodCloseService::class);

    $first = $service->closingDocumentType($company);
    $second = $service->closingDocumentType($company);

    expect($second->id)->toBe($first->id)
        ->and(DocumentType::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->where('code', 'ACC')->count())->toBe(1);
});

it('createNextYear crea el primer año fiscal de una compañía sin ninguno, con 12 períodos mensuales', function () {
    $company = Company::factory()->create();

    $fiscalYear = app(PeriodCloseService::class)->createNextYear($company);

    expect($fiscalYear->year)->toBe((int) now()->format('Y'))
        ->and($fiscalYear->status)->toBe('open')
        ->and($fiscalYear->periods)->toHaveCount(12)
        ->and($fiscalYear->periods->every(fn ($p) => $p->status === 'open'))->toBeTrue();

    $year = (int) now()->format('Y');
    $jan = $fiscalYear->periods->firstWhere('period_number', 1);
    expect($jan->start_date->format('Y-m-d'))->toBe("{$year}-01-01")
        ->and($jan->end_date->format('Y-m-d'))->toBe("{$year}-01-31");

    $dec = $fiscalYear->periods->firstWhere('period_number', 12);
    expect($dec->start_date->format('Y-m-d'))->toBe("{$year}-12-01")
        ->and($dec->end_date->format('Y-m-d'))->toBe("{$year}-12-31");
});

it('createNextYear crea el año siguiente al más reciente que ya exista, sin exigir que esté cerrado', function () {
    $company = Company::factory()->create();
    FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026, 'status' => 'open']);

    $next = app(PeriodCloseService::class)->createNextYear($company);

    expect($next->year)->toBe(2027)
        ->and($next->periods)->toHaveCount(12);
});

it('rechaza cerrar un período que tiene documentos en borrador', function () {
    $fx = periodCloseFixture();

    JournalEntry::withoutGlobalScope(CompanyScope::class)->create([
        'company_id' => $fx['company']->id,
        'document_type_id' => $fx['add']->id,
        'document_number' => 1,
        'document_date' => '2026-01-10',
        'posting_date' => '2026-01-10',
        'fiscal_period_id' => $fx['jan']->id,
        'status' => 'draft',
    ]);

    app(PeriodCloseService::class)->close($fx['company'], $fx['jan']);
})->throws(DraftEntriesExistException::class);

it('rechaza cerrar un período con un borrador real de saveDraft(), aunque no tenga fiscal_period_id asignado', function () {
    $fx = periodCloseFixture();

    // saveDraft() nunca asigna fiscal_period_id (eso se resuelve recién al
    // contabilizar formalmente) — el guard de close() debe detectar el
    // borrador por su posting_date, no por esa columna, o quedaría ciego
    // a todo borrador real creado desde la UI.
    app(PostJournalService::class)->saveDraft(
        $fx['company'],
        $fx['add'],
        new DateTime('2026-01-10'),
        new DateTime('2026-01-10'),
        [new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 100, credit: 0)],
    );

    app(PeriodCloseService::class)->close($fx['company'], $fx['jan']);
})->throws(DraftEntriesExistException::class);

it('cierra un período sin borradores y lo puede reabrir después', function () {
    $fx = periodCloseFixture();

    $service = app(PeriodCloseService::class);
    $closed = $service->close($fx['company'], $fx['jan']);
    expect($closed->status)->toBe('closed');

    $reopened = $service->reopen($fx['company'], $closed);
    expect($reopened->status)->toBe('open');
});

it('exige que los períodos previos estén cerrados antes de cerrar el año', function () {
    $fx = periodCloseFixture();

    app(PeriodCloseService::class)->closeYear($fx['company'], $fx['fiscalYear'], $fx['acc'], $fx['retainedEarnings']);
})->throws(InvalidPeriodTransitionException::class);

it('cierra el año generando el asiento ACC que traslada el resultado neto a utilidades acumuladas', function () {
    $fx = periodCloseFixture();

    $journalService = app(PostJournalService::class);

    // Venta de 1000 en enero.
    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
    ], 'Venta de contado');

    // Gasto de 400 en febrero.
    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-02-05'), new DateTime('2026-02-05'), [
        new JournalLineInput($fx['expense']->id, $fx['company']->local_currency_id, debit: 400, credit: 0),
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: 400),
    ], 'Gasto operativo');

    $service = app(PeriodCloseService::class);
    $service->close($fx['company'], $fx['jan']);

    $process = $service->closeYear($fx['company'], $fx['fiscalYear'], $fx['acc'], $fx['retainedEarnings']);

    expect($process->type)->toBe('year_close')
        ->and($process->journal_entry_id)->not->toBeNull();

    $closingEntry = JournalEntry::withoutGlobalScope(CompanyScope::class)
        ->with('details')->find($process->journal_entry_id);

    expect($closingEntry->details)->toHaveCount(3);

    $reLine = $closingEntry->details->firstWhere('account_id', $fx['retainedEarnings']->id);
    expect($reLine->credit_local)->toEqual('600.00');

    $salesLine = $closingEntry->details->firstWhere('account_id', $fx['sales']->id);
    expect($salesLine->debit_local)->toEqual('1000.00');

    $expenseLine = $closingEntry->details->firstWhere('account_id', $fx['expense']->id);
    expect($expenseLine->credit_local)->toEqual('400.00');

    expect($fx['fiscalYear']->fresh()->status)->toBe('closed')
        ->and($fx['feb']->fresh()->status)->toBe('closed');
});
