<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Banking\Exceptions\UnbalancedReconciliationException;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankReconciliation;
use App\Domains\Banking\Models\BankReconciliationLine;
use App\Domains\Banking\Models\BankStatementLine;
use App\Domains\Banking\Services\BankReconciliationReportService;
use App\Domains\Banking\Services\BankReconciliationService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function bankingFixture(): array
{
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $bankGlAccount = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);

    $bankAccount = BankAccount::factory()->create([
        'company_id' => $company->id,
        'gl_account_id' => $bankGlAccount->id,
        'currency_id' => $company->local_currency_id,
    ]);

    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    $service = app(PostJournalService::class);

    // Depósito de 1000 ya acreditado por el banco.
    $service->post($company, $add, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($bankGlAccount->id, $company->local_currency_id, debit: 1000, credit: 0),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 1000),
    ], 'Depósito inicial');

    // Cheque de 200 girado pero aún no pagado por el banco.
    $service->post($company, $add, new DateTime('2026-01-10'), new DateTime('2026-01-10'), [
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 200, credit: 0),
        new JournalLineInput($bankGlAccount->id, $company->local_currency_id, debit: 0, credit: 200),
    ], 'Cheque girado');

    return compact('company', 'bankAccount', 'bankGlAccount', 'equity');
}

it('abre la conciliación con todas las partidas pendientes de confirmar contra el banco', function () {
    $fx = bankingFixture();

    $reconciliation = app(BankReconciliationService::class)->open(
        $fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000
    );

    // Nada se asume conciliado por defecto: ambas líneas cuentan como
    // pendientes hasta que el usuario las confirme contra el estado bancario.
    expect($reconciliation->book_balance)->toEqual('800.00')
        ->and($reconciliation->bank_balance)->toEqual('1000.00')
        ->and($reconciliation->unpaid_checks)->toEqual('200.00')
        ->and($reconciliation->unrecorded_deposits)->toEqual('1000.00')
        ->and($reconciliation->lines)->toHaveCount(2);
});

it('cuadra la conciliación cuando se confirma el depósito y el único pendiente es el cheque', function () {
    $fx = bankingFixture();

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);

    $depositLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');
    $service->confirmInBank($depositLine);

    $closed = $service->close($reconciliation);

    expect($closed->status)->toBe('completed')
        ->and($closed->unpaid_checks)->toEqual('200.00')
        ->and($closed->unrecorded_deposits)->toEqual('0.00');
});

it('deja de cuadrar si además se confirma el cheque sin actualizar el saldo del banco', function () {
    $fx = bankingFixture();

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);

    $depositLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');
    $service->confirmInBank($depositLine);

    $checkLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->credit_local == '200.00');
    $service->confirmInBank($checkLine);

    $service->close($reconciliation);
})->throws(UnbalancedReconciliationException::class);

it('suma los créditos de banco no registrados en libros al saldo ajustado', function () {
    $fx = bankingFixture();

    BankStatementLine::create([
        'bank_account_id' => $fx['bankAccount']->id,
        'statement_date' => '2026-01-28',
        'description' => 'Intereses ganados',
        'amount' => '50.00',
        'matched' => false,
    ]);

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);

    expect($reconciliation->unrecorded_bank_credits)->toEqual('50.00')
        ->and($reconciliation->adjustedBookBalance())->toEqual('850.00');
});

it('confirmInBank alterna el check: una segunda llamada desmarca la línea', function () {
    $fx = bankingFixture();

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);
    $depositLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');

    $service->confirmInBank($depositLine);
    expect($depositLine->fresh()->matched_in_bank)->toBeTrue();

    $service->confirmInBank($depositLine);
    expect($depositLine->fresh()->matched_in_bank)->toBeFalse();
});

it('confirmInBank suelta la línea de estado de cuenta enlazada al desmarcar', function () {
    $fx = bankingFixture();

    $statementLine = BankStatementLine::create([
        'bank_account_id' => $fx['bankAccount']->id,
        'statement_date' => '2026-01-05',
        'description' => 'Depósito',
        'amount' => '1000.00',
        'matched' => false,
    ]);

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);
    $depositLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');

    $service->confirmInBank($depositLine, $statementLine);
    expect($statementLine->fresh()->matched)->toBeTrue();

    $service->confirmInBank($depositLine);
    expect($depositLine->fresh()->bank_statement_line_id)->toBeNull()
        ->and($statementLine->fresh()->matched)->toBeFalse();
});

it('una segunda conciliación no vuelve a pedir confirmar un movimiento ya confirmado en una anterior', function () {
    $fx = bankingFixture();

    $service = app(BankReconciliationService::class);
    $first = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);

    $depositLine = $first->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');
    $service->confirmInBank($depositLine);
    $service->close($first->fresh());

    // Segunda conciliación, corte posterior: el depósito ya confirmado y
    // cerrado en la primera NO debe volver a aparecer como pendiente — bug
    // real corregido 2026-08-27 (antes reaparecía sin confirmar cada vez).
    // El cheque, que nunca se confirmó, sigue genuinamente pendiente y sí
    // tiene que seguir apareciendo — no es "todo lo visto antes", es
    // específicamente "lo ya confirmado".
    $second = $service->open($fx['bankAccount'], new DateTime('2026-02-28'), bankBalance: 800);

    expect($second->lines)->toHaveCount(1)
        ->and($second->lines->first()->journalDetail->credit_local)->toEqual('200.00')
        ->and($second->unrecorded_deposits)->toEqual('0.00')
        ->and($second->unpaid_checks)->toEqual('200.00');
});

it('reopen() vuelve a poner en_proceso una conciliación cerrada, sin tocar sus líneas', function () {
    $fx = bankingFixture();

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);

    // Solo el depósito confirmado: igual que el test de "cuadra la
    // conciliación..." más arriba, así queda balanceada (el cheque sigue
    // pendiente, que es justamente lo esperado a la fecha de corte).
    $depositLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');
    $service->confirmInBank($depositLine);

    $service->close($reconciliation);
    expect($reconciliation->fresh()->status)->toBe('completed');

    // close() no muta el objeto $reconciliation original en memoria (trabaja
    // sobre lo que le devuelve recalculate(), una instancia nueva) — hay que
    // releerlo antes de reabrir, igual que ya hace el controlador real.
    $reopened = $service->reopen($reconciliation->fresh());

    expect($reopened->status)->toBe('in_progress')
        ->and($reopened->lines)->toHaveCount(2);
});

it('delete() elimina la conciliación y sus líneas sin tocar los journal_details', function () {
    $fx = bankingFixture();

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);
    $reconciliationId = $reconciliation->id;
    $detailIds = $reconciliation->lines->pluck('journal_detail_id');

    $service->delete($reconciliation);

    expect(BankReconciliation::find($reconciliationId))->toBeNull()
        ->and(BankReconciliationLine::where('bank_reconciliation_id', $reconciliationId)->count())->toBe(0);

    foreach ($detailIds as $detailId) {
        expect(\App\Domains\Accounting\Models\JournalDetail::find($detailId))->not->toBeNull();
    }
});

it('BankReconciliationReportService::build() filtra por año y mes del corte, con los saldos ajustados', function () {
    $fx = bankingFixture();

    $service = app(BankReconciliationService::class);
    $january = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);
    $depositLine = $january->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');
    $service->confirmInBank($depositLine);
    $service->close($january->fresh());

    $service->open($fx['bankAccount'], new DateTime('2026-02-15'), bankBalance: 800);

    $rows = app(BankReconciliationReportService::class)->build($fx['bankAccount'], 2026, 1);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->cutoffDate)->toBe('2026-01-31')
        ->and($rows[0]->statusLabel)->toBe('Cerrada')
        ->and($rows[0]->isBalanced)->toBeTrue()
        // bank_balance 1000 + depósitos no acreditados 0 - cheque pendiente 200.
        ->and($rows[0]->adjustedBankBalance)->toEqual('800.00');

    // Detalle línea por línea: el depósito ya confirmado en banco, y el
    // cheque que sigue pendiente (exactamente lo que compone unpaid_checks).
    expect($rows[0]->lines)->toHaveCount(2);

    $depositDetail = collect($rows[0]->lines)->first(fn ($l) => $l->type === 'deposito');
    $checkDetail = collect($rows[0]->lines)->first(fn ($l) => $l->type === 'cheque');

    expect($depositDetail->matchedInBank)->toBeTrue()
        ->and($depositDetail->debit)->toEqual('1000.00')
        ->and($checkDetail->matchedInBank)->toBeFalse()
        ->and($checkDetail->credit)->toEqual('200.00');
});

it('BankReconciliationReportService::build() devuelve vacío para un mes sin conciliaciones', function () {
    $fx = bankingFixture();

    app(BankReconciliationService::class)->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);

    $rows = app(BankReconciliationReportService::class)->build($fx['bankAccount'], 2026, 6);

    expect($rows)->toBeEmpty();
});

it('delete() suelta las líneas de estado de cuenta que estaban enlazadas', function () {
    $fx = bankingFixture();

    $statementLine = BankStatementLine::create([
        'bank_account_id' => $fx['bankAccount']->id,
        'statement_date' => '2026-01-05',
        'description' => 'Depósito',
        'amount' => '1000.00',
        'matched' => false,
    ]);

    $service = app(BankReconciliationService::class);
    $reconciliation = $service->open($fx['bankAccount'], new DateTime('2026-01-31'), bankBalance: 1000);
    $depositLine = $reconciliation->lines->first(fn ($l) => $l->journalDetail->debit_local == '1000.00');
    $service->confirmInBank($depositLine, $statementLine);

    expect($statementLine->fresh()->matched)->toBeTrue();

    $service->delete($reconciliation);

    expect($statementLine->fresh()->matched)->toBeFalse();
});
