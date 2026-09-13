<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('calcula saldo inicial, movimientos y saldo final de una cuenta débito-normal dentro de un rango', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $service->post($company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);
    $service->post($company, $documentType, new DateTime('2026-01-15'), new DateTime('2026-01-15'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 200, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 200),
    ]);
    $service->post($company, $documentType, new DateTime('2026-01-25'), new DateTime('2026-01-25'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: 50),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 50, credit: 0),
    ]);

    $ledger = app(LedgerService::class)->build($company, 'account', $cash->id, '2026-01-10', '2026-01-20');

    expect($ledger->openingBalance)->toBe('100.00')
        ->and($ledger->movements)->toHaveCount(1)
        ->and($ledger->movements[0]->balance)->toBe('300.00')
        ->and($ledger->closingBalance)->toBe('300.00');
});

it('invierte el signo del saldo para una cuenta crédito-normal', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();

    $payable = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '2-01-01-01-001',
        'accepts_posting' => true, 'normal_balance' => 'credit',
    ]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 300, credit: 0),
        new JournalLineInput($payable->id, $company->local_currency_id, debit: 0, credit: 300),
    ]);

    $ledger = app(LedgerService::class)->build($company, 'account', $payable->id, null, null);

    expect($ledger->closingBalance)->toBe('300.00');
});

it('el mayor de un socio de negocio usa la naturaleza de su cuenta contable vinculada', function () {
    ['company' => $company, 'cash' => $cash, 'documentType' => $documentType] = contappFixture();

    $payable = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '2-01-01-01-001',
        'accepts_posting' => true, 'normal_balance' => 'credit',
    ]);

    $supplier = BusinessPartner::create([
        'company_id' => $company->id, 'code' => 'P-001', 'name' => 'Proveedor SA', 'type' => 'supplier',
        'gl_account_id' => $payable->id, 'currency_id' => $company->local_currency_id, 'status' => 'active',
    ]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 150, credit: 0),
        new JournalLineInput($payable->id, $company->local_currency_id, debit: 0, credit: 150, businessPartnerId: $supplier->id),
    ]);

    $ledger = app(LedgerService::class)->build($company, 'business-partner', $supplier->id, null, null);

    expect($ledger->normalBalance)->toBe('credit')
        ->and($ledger->closingBalance)->toBe('150.00');
});

it('el mayor de un centro de costo usa convención saldo deudor', function () {
    ['company' => $company, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();

    $gasto = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '6-01-01-01-001', 'accepts_posting' => true]);
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => '01']);
    $rule = CostAllocationRule::factory()->create(['company_id' => $company->id, 'code' => 'NORMA1']);
    $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => '100.00', 'position' => 1]);

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($gasto->id, $company->local_currency_id, debit: 75, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 75),
    ]);

    $ledger = app(LedgerService::class)->build($company, 'cost-center', $cc->id, null, null);

    expect($ledger->normalBalance)->toBe('debit')
        ->and($ledger->closingBalance)->toBe('75.00');
});

it('no cuenta asientos en borrador, solo los contabilizados', function () {
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType, 'period' => $period] = contappFixture();

    app(PostJournalService::class)->post($company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $draft = JournalEntry::create([
        'company_id' => $company->id, 'document_type_id' => $documentType->id, 'document_number' => 999,
        'document_date' => '2026-01-10', 'posting_date' => '2026-01-10', 'status' => 'draft', 'fiscal_period_id' => $period->id,
    ]);
    JournalDetail::create([
        'journal_entry_id' => $draft->id, 'line_number' => 1, 'account_id' => $cash->id,
        'currency_id' => $company->local_currency_id, 'debit_local' => 5000, 'credit_local' => 0,
        'debit_foreign' => 0, 'credit_foreign' => 0, 'debit_system' => 0, 'credit_system' => 0,
    ]);

    $ledger = app(LedgerService::class)->build($company, 'account', $cash->id, null, null);

    expect($ledger->closingBalance)->toBe('100.00')
        ->and($ledger->movements)->toHaveCount(1);
});

it('un asiento anulado y su reversión netean exactamente en cero en el saldo, no se excluyen del cálculo', function () {
    // Bug real encontrado y corregido 2026-09-09: LedgerService excluía
    // status='voided' del todo, así que el espejo de reverse() (que SÍ
    // queda 'posted', con signo contrario) no tenía nada que cancelar y el
    // saldo quedaba descuadrado por el monto completo de la reversión.
    ['company' => $company, 'cash' => $cash, 'capital' => $capital, 'documentType' => $documentType] = contappFixture();
    $service = app(PostJournalService::class);

    $service->post($company, $documentType, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $balanceBefore = app(LedgerService::class)->build($company, 'account', $cash->id, null, null)->closingBalance;

    $toReverse = $service->post($company, $documentType, new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 500, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 500),
    ]);
    $service->reverse($company, $toReverse, new DateTime('2026-01-11'));

    $ledger = app(LedgerService::class)->build($company, 'account', $cash->id, null, null);

    expect($ledger->closingBalance)->toBe($balanceBefore)
        ->and($ledger->closingBalance)->toBe('100.00')
        ->and($ledger->movements)->toHaveCount(3); // original + el reversado (voided) + su espejo, todos visibles
});

it('excluye el asiento de cierre anual del mayor de cuentas de resultados, pero lo muestra en utilidades acumuladas', function () {
    $fx = periodCloseFixture();
    $journalService = app(PostJournalService::class);

    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($fx['sales']->id, $fx['company']->local_currency_id, debit: 0, credit: 1000),
    ], 'Venta de contado');

    $journalService->post($fx['company'], $fx['add'], new DateTime('2026-02-05'), new DateTime('2026-02-05'), [
        new JournalLineInput($fx['expense']->id, $fx['company']->local_currency_id, debit: 400, credit: 0),
        new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: 400),
    ], 'Gasto operativo');

    $closeService = app(\App\Domains\Accounting\Services\PeriodCloseService::class);
    $closeService->close($fx['company'], $fx['jan']);
    $closeService->closeYear($fx['company'], $fx['fiscalYear'], $fx['acc'], $fx['retainedEarnings']);

    $ledgerService = app(LedgerService::class);

    // Ventas y gastos: el mayor debe mostrar SOLO la venta/el gasto real,
    // sin la línea del cierre que los cancela — si no, el saldo del año
    // quedaría en 0.00 y escondería cuánto se vendió/gastó de verdad.
    $salesLedger = $ledgerService->build($fx['company'], 'account', $fx['sales']->id, null, null);
    expect($salesLedger->movements)->toHaveCount(1)
        ->and($salesLedger->closingBalance)->toBe('1000.00');

    $expenseLedger = $ledgerService->build($fx['company'], 'account', $fx['expense']->id, null, null);
    expect($expenseLedger->movements)->toHaveCount(1)
        ->and($expenseLedger->closingBalance)->toBe('400.00');

    // Utilidades acumuladas SÍ debe mostrar el asiento de cierre — es
    // justo el movimiento que explica de dónde salió su saldo.
    $retainedLedger = $ledgerService->build($fx['company'], 'account', $fx['retainedEarnings']->id, null, null);
    expect($retainedLedger->movements)->toHaveCount(1)
        ->and($retainedLedger->closingBalance)->toBe('600.00');
});

it('lanza una excepción para una dimensión de mayor desconocida', function () {
    ['company' => $company, 'cash' => $cash] = contappFixture();

    app(LedgerService::class)->build($company, 'unknown-thing', $cash->id, null, null);
})->throws(InvalidArgumentException::class);

it('lanza not-found para un id que pertenece a otra compañía', function () {
    ['company' => $company] = contappFixture();
    $otherCompany = Company::factory()->create();
    $otherAccount = ChartOfAccount::factory()->create(['company_id' => $otherCompany->id]);

    app(LedgerService::class)->build($company, 'account', $otherAccount->id, null, null);
})->throws(ModelNotFoundException::class);
