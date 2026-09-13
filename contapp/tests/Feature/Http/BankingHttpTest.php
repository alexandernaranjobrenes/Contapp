<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankReconciliation;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

function bankingHttpFixture(): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $bankGl = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);

    $bankAccount = BankAccount::create([
        'company_id' => $company->id,
        'gl_account_id' => $bankGl->id,
        'bank_name' => 'Banco de Prueba',
        'account_number' => 'CR001',
        'currency_id' => $company->local_currency_id,
    ]);

    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    app(PostJournalService::class)->post($company, $add, now(), now(), [
        new JournalLineInput($bankGl->id, $company->local_currency_id, debit: 500, credit: 0),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 500),
    ], 'Depósito');

    return compact('user', 'company', 'bankGl', 'bankAccount');
}

it('rechaza crear una cuenta bancaria sobre una cuenta contable que no es monetaria', function () {
    ['company' => $company] = logInAsCompanyUser();

    $notCash = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-01-01-009', 'is_cash_account' => false,
    ]);

    $this->post(route('bank-accounts.store'), [
        'bank_name' => 'Banco X',
        'account_number' => 'CR999',
        'gl_account_id' => $notCash->id,
        'currency_id' => $company->local_currency_id,
    ])->assertSessionHasErrors('gl_account_id');
});

it('solo lista las cuentas bancarias de la compañía activa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $glA = ChartOfAccount::factory()->create(['company_id' => $companyA->id]);
    $glB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);

    $bankA = BankAccount::create([
        'company_id' => $companyA->id, 'gl_account_id' => $glA->id,
        'bank_name' => 'A', 'account_number' => '1', 'currency_id' => $companyA->local_currency_id,
    ]);
    BankAccount::create([
        'company_id' => $companyB->id, 'gl_account_id' => $glB->id,
        'bank_name' => 'B', 'account_number' => '2', 'currency_id' => $companyB->local_currency_id,
    ]);

    logInAsCompanyUser($companyA);

    $this->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Banking/Index')
            ->has('bankAccounts', 1)
            ->where('bankAccounts.0.bank_name', $bankA->bank_name)
        );
});

it('edita el nombre, número y cuenta contable de una cuenta bancaria', function () {
    $fx = bankingHttpFixture();
    $newGl = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-01-01-002', 'is_cash_account' => true,
    ]);

    $this->put(route('bank-accounts.update', $fx['bankAccount']->id), [
        'bank_name' => 'Banco Renombrado',
        'account_number' => 'CR002',
        'gl_account_id' => $newGl->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    $fresh = $fx['bankAccount']->fresh();
    expect($fresh->bank_name)->toBe('Banco Renombrado')
        ->and($fresh->account_number)->toBe('CR002')
        ->and($fresh->gl_account_id)->toBe($newGl->id);
});

it('rechaza editar una cuenta bancaria hacia una cuenta contable que no es monetaria', function () {
    $fx = bankingHttpFixture();
    $notCash = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-01-01-009', 'is_cash_account' => false,
    ]);

    $this->put(route('bank-accounts.update', $fx['bankAccount']->id), [
        'bank_name' => $fx['bankAccount']->bank_name,
        'account_number' => $fx['bankAccount']->account_number,
        'gl_account_id' => $notCash->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasErrors('gl_account_id');
});

it('rechaza editar una cuenta bancaria hacia una cuenta contable que ya usa otra cuenta bancaria', function () {
    $fx = bankingHttpFixture();
    $otherGl = ChartOfAccount::factory()->create([
        'company_id' => $fx['company']->id, 'code' => '1-01-01-01-003', 'is_cash_account' => true,
    ]);
    BankAccount::create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $otherGl->id,
        'bank_name' => 'Otro banco', 'account_number' => 'CR777', 'currency_id' => $fx['company']->local_currency_id,
    ]);

    $this->put(route('bank-accounts.update', $fx['bankAccount']->id), [
        'bank_name' => $fx['bankAccount']->bank_name,
        'account_number' => $fx['bankAccount']->account_number,
        'gl_account_id' => $otherGl->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasErrors('gl_account_id');
});

it('permite editar una cuenta bancaria conservando su propia cuenta contable, sin rechazarla como duplicada', function () {
    $fx = bankingHttpFixture();
    // bankingHttpFixture() crea $bankGl directo por BankAccount::create(),
    // sin pasar por store() — nunca quedó marcada is_cash_account, a
    // diferencia de cómo quedaría en un flujo real. Se corrige acá para que
    // esta prueba (que sí pasa por la validación de update()) sea realista.
    $fx['bankGl']->update(['is_cash_account' => true]);

    $this->put(route('bank-accounts.update', $fx['bankAccount']->id), [
        'bank_name' => 'Mismo banco, nombre ajustado',
        'account_number' => $fx['bankAccount']->account_number,
        'gl_account_id' => $fx['bankGl']->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    expect($fx['bankAccount']->fresh()->bank_name)->toBe('Mismo banco, nombre ajustado');
});

it('rechaza editar una cuenta bancaria de otra compañía', function () {
    $companyB = Company::factory()->create();
    $glB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $bankB = BankAccount::create([
        'company_id' => $companyB->id, 'gl_account_id' => $glB->id,
        'bank_name' => 'Ajeno', 'account_number' => '999', 'currency_id' => $companyB->local_currency_id,
    ]);

    logInAsCompanyUser();

    $this->put(route('bank-accounts.update', $bankB->id), [
        'bank_name' => 'Intento ajeno',
        'account_number' => '000',
        'gl_account_id' => $glB->id,
        'currency_id' => $companyB->local_currency_id,
    ])->assertNotFound();
});

it('abre una conciliación y la muestra con el saldo de libros calculado', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ])->assertRedirect();

    $reconciliation = BankReconciliation::sole();

    // No cuadra todavía: nada se asume conciliado hasta que se confirma
    // explícitamente contra el banco (ver docs/decisiones.md 2026-08-05).
    $this->get(route('bank-reconciliations.show', $reconciliation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Banking/Reconciliations/Show')
            ->where('reconciliation.book_balance', '500.00')
            ->where('isBalanced', false)
        );
});

it('confirma una línea contra el banco y cierra la conciliación balanceada', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();
    $line = $reconciliation->lines()->sole();

    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]))
        ->assertRedirect();

    $this->post(route('bank-reconciliations.close', $reconciliation->id))
        ->assertSessionHasNoErrors();

    expect($reconciliation->fresh()->status)->toBe('completed');
});

it('rechaza ver, confirmar o cerrar una conciliación de otra compañía', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();
    $line = $reconciliation->lines()->sole();

    // Otra compañía, otro usuario: BankReconciliation/-Line no tienen
    // CompanyScope propio, así que sin el chequeo explícito de bankAccount
    // en el controlador, estos ids serían accesibles entre compañías.
    logInAsCompanyUser(Company::factory()->create());

    $this->get(route('bank-reconciliations.show', $reconciliation->id))->assertNotFound();
    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]))->assertNotFound();
    $this->post(route('bank-reconciliations.close', $reconciliation->id))->assertNotFound();
});

it('rechaza cerrar una conciliación que no cuadra', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 999,
    ]);

    $reconciliation = BankReconciliation::sole();

    $this->post(route('bank-reconciliations.close', $reconciliation->id))
        ->assertSessionHasErrors('balance');

    expect($reconciliation->fresh()->status)->toBe('in_progress');
});

it('confirmar una línea ya marcada la desmarca (checkbox de doble función)', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();
    $line = $reconciliation->lines()->sole();

    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]));
    expect($line->fresh()->matched_in_bank)->toBeTrue();

    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]));
    expect($line->fresh()->matched_in_bank)->toBeFalse();
});

it('reabre una conciliación cerrada y vuelve a permitir tocar sus checks', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();
    $line = $reconciliation->lines()->sole();

    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]));
    $this->post(route('bank-reconciliations.close', $reconciliation->id))->assertSessionHasNoErrors();
    expect($reconciliation->fresh()->status)->toBe('completed');

    // Cerrada: ya no se puede tocar el check.
    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]))->assertNotFound();

    $this->post(route('bank-reconciliations.reopen', $reconciliation->id))->assertSessionHasNoErrors();
    expect($reconciliation->fresh()->status)->toBe('in_progress');

    // Reabierta: vuelve a aceptar el toggle.
    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]))->assertRedirect();
    expect($line->fresh()->matched_in_bank)->toBeFalse();
});

it('rechaza reabrir una conciliación de otra compañía', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();
    $line = $reconciliation->lines()->sole();
    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]));
    $this->post(route('bank-reconciliations.close', $reconciliation->id))->assertSessionHasNoErrors();
    expect($reconciliation->fresh()->status)->toBe('completed');

    logInAsCompanyUser(Company::factory()->create());

    $this->post(route('bank-reconciliations.reopen', $reconciliation->id))->assertNotFound();
    expect($reconciliation->fresh()->status)->toBe('completed');
});

it('elimina una conciliación en proceso y redirige al listado de la cuenta', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();

    $this->delete(route('bank-reconciliations.destroy', $reconciliation->id))
        ->assertRedirect(route('bank-reconciliations.index', $fx['bankAccount']->id));

    expect(BankReconciliation::find($reconciliation->id))->toBeNull();
});

it('elimina una conciliación cerrada sin problema', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();
    $line = $reconciliation->lines()->sole();
    $this->post(route('bank-reconciliations.confirm-line', [$reconciliation->id, $line->id]));
    $this->post(route('bank-reconciliations.close', $reconciliation->id))->assertSessionHasNoErrors();

    $this->delete(route('bank-reconciliations.destroy', $reconciliation->id))->assertSessionHasNoErrors();

    expect(BankReconciliation::find($reconciliation->id))->toBeNull();
});

it('rechaza eliminar una conciliación de otra compañía', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $reconciliation = BankReconciliation::sole();

    logInAsCompanyUser(Company::factory()->create());

    $this->delete(route('bank-reconciliations.destroy', $reconciliation->id))->assertNotFound();
    expect(BankReconciliation::find($reconciliation->id))->not->toBeNull();
});

it('el hub de conciliaciones bancarias muestra cada cuenta con su última conciliación', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $this->get(route('bank-reconciliations.hub'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Banking/ReconciliationsHub')
            ->has('bankAccounts', 1)
            ->where('bankAccounts.0.bank_name', $fx['bankAccount']->bank_name)
            ->where('bankAccounts.0.last_reconciliation.status', 'in_progress')
        );
});

it('el hub de conciliaciones bancarias marca una cuenta sin conciliar todavía', function () {
    $fx = bankingHttpFixture();

    $this->get(route('bank-reconciliations.hub'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('bankAccounts.0.last_reconciliation', null)
        );
});

it('el reporte de conciliaciones lista las del mes/año/cuenta elegidos', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $this->get(route('bank-reconciliation-report.index', [
        'bank_account_id' => $fx['bankAccount']->id,
        'year' => (int) now()->format('Y'),
        'month' => (int) now()->format('n'),
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Banking/ReconciliationReport')
            ->has('rows', 1)
            ->where('rows.0.status', 'in_progress')
            ->where('rows.0.book_balance', '500.00')
            ->has('rows.0.lines', 1)
            ->has('rows.0.lines.0.reference_document')
            ->has('rows.0.lines.0.reference_document_date')
        );
});

it('el reporte de conciliaciones no trae nada sin cuenta bancaria seleccionada', function () {
    $fx = bankingHttpFixture();

    $this->get(route('bank-reconciliation-report.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Banking/ReconciliationReport')
            ->has('rows', 0)
        );
});

it('el reporte de conciliaciones excluye conciliaciones fuera del mes/año elegido', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->subMonthNoOverflow()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $this->get(route('bank-reconciliation-report.index', [
        'bank_account_id' => $fx['bankAccount']->id,
        'year' => (int) now()->format('Y'),
        'month' => (int) now()->format('n'),
    ]))
        ->assertInertia(fn ($page) => $page->has('rows', 0));
});

it('exporta el reporte de conciliaciones a XLSX', function () {
    $fx = bankingHttpFixture();

    $this->post(route('bank-reconciliations.store', $fx['bankAccount']->id), [
        'cutoff_date' => now()->format('Y-m-d'),
        'bank_balance' => 500,
    ]);

    $response = $this->get(route('bank-reconciliation-report.export', [
        'bank_account_id' => $fx['bankAccount']->id,
        'year' => (int) now()->format('Y'),
        'month' => (int) now()->format('n'),
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('rechaza exportar el reporte de conciliaciones sin cuenta bancaria seleccionada', function () {
    bankingHttpFixture();

    $this->get(route('bank-reconciliation-report.export', [
        'year' => (int) now()->format('Y'),
        'month' => (int) now()->format('n'),
    ]))->assertSessionHasErrors('bank_account_id');
});

it('rechaza consultar el reporte de conciliaciones con una cuenta de otra compañía', function () {
    $fx = bankingHttpFixture();
    $companyB = Company::factory()->create();
    $glB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);
    $bankB = BankAccount::create([
        'company_id' => $companyB->id, 'gl_account_id' => $glB->id,
        'bank_name' => 'Ajeno', 'account_number' => '999', 'currency_id' => $companyB->local_currency_id,
    ]);

    $this->get(route('bank-reconciliation-report.index', [
        'bank_account_id' => $bankB->id,
        'year' => (int) now()->format('Y'),
        'month' => (int) now()->format('n'),
    ]))->assertSessionHasErrors('bank_account_id');
});
