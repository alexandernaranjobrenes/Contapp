<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\AccountReconciliation;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;

function accountReconciliationHttpFixture(): array
{
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $suspense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-09-01-001', 'requires_business_partner' => false,
    ]);
    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    return compact('user', 'company', 'suspense', 'cash', 'add');
}

/**
 * Contabiliza un asiento de 2 líneas (cuenta A débito / cuenta B crédito por
 * el mismo monto) y devuelve el JournalDetail que cae en $primaryAccount.
 */
function postSuspenseMovement(array $fx, ChartOfAccount $primaryAccount, bool $primaryIsDebit, string $amount): JournalDetail
{
    $entry = app(PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new JournalLineInput($primaryAccount->id, $fx['company']->local_currency_id, debit: $primaryIsDebit ? $amount : 0, credit: $primaryIsDebit ? 0 : $amount),
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: $primaryIsDebit ? 0 : $amount, credit: $primaryIsDebit ? $amount : 0),
        ],
    );

    return $entry->details->firstWhere('account_id', $primaryAccount->id);
}

it('muestra los movimientos sin reconciliar de una cuenta', function () {
    $fx = accountReconciliationHttpFixture();
    postSuspenseMovement($fx, $fx['suspense'], true, '500');

    $this->get(route('account-reconciliation.index', $fx['suspense']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('AccountReconciliation/Index')
            ->where('account.code', $fx['suspense']->code)
            ->has('unreconciled', 1)
            ->where('unreconciled.0.debit_local', '500.00')
        );
});

it('reconcilia dos movimientos de la misma cuenta cuyo neto es cero', function () {
    $fx = accountReconciliationHttpFixture();
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');
    $d2 = postSuspenseMovement($fx, $fx['suspense'], false, '500');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), [
        'lines' => [
            ['journal_detail_id' => $d1->id, 'amount' => '500'],
            ['journal_detail_id' => $d2->id, 'amount' => '500'],
        ],
    ])->assertSessionHasNoErrors();

    $reconciliation = AccountReconciliation::where('account_id', $fx['suspense']->id)->sole();
    expect($reconciliation->lines)->toHaveCount(2)
        ->and($reconciliation->lines->pluck('journal_detail_id')->sort()->values()->all())->toBe([$d1->id, $d2->id])
        ->and($reconciliation->lines->pluck('amount')->map(fn ($a) => (string) $a)->all())->toBe(['500.00', '500.00']);
});

it('reconcilia solo una parte de un documento y deja el resto disponible', function () {
    $fx = accountReconciliationHttpFixture();
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '1000');
    $d2 = postSuspenseMovement($fx, $fx['suspense'], false, '400');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), [
        'lines' => [
            ['journal_detail_id' => $d1->id, 'amount' => '400'],
            ['journal_detail_id' => $d2->id, 'amount' => '400'],
        ],
    ])->assertSessionHasNoErrors();

    expect($d1->fresh()->availableReconciliationAmount())->toEqual('600.00')
        ->and($d2->fresh()->availableReconciliationAmount())->toEqual('0.00');

    $this->get(route('account-reconciliation.index', $fx['suspense']->id))
        ->assertInertia(fn ($page) => $page
            ->has('unreconciled', 1)
            ->where('unreconciled.0.id', $d1->id)
            ->where('unreconciled.0.available_amount', '600.00')
        );

    // Lo que quedó libre se puede reconciliar después, en un evento aparte.
    $d3 = postSuspenseMovement($fx, $fx['suspense'], false, '600');
    $this->post(route('account-reconciliation.store', $fx['suspense']->id), [
        'lines' => [
            ['journal_detail_id' => $d1->id, 'amount' => '600'],
            ['journal_detail_id' => $d3->id, 'amount' => '600'],
        ],
    ])->assertSessionHasNoErrors();

    expect($d1->fresh()->availableReconciliationAmount())->toEqual('0.00');
});

it('rechaza reconciliar un monto mayor al disponible de un movimiento', function () {
    $fx = accountReconciliationHttpFixture();
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');
    $d2 = postSuspenseMovement($fx, $fx['suspense'], false, '500');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), [
        'lines' => [
            ['journal_detail_id' => $d1->id, 'amount' => '600'],
            ['journal_detail_id' => $d2->id, 'amount' => '600'],
        ],
    ])->assertSessionHasErrors('reconciliation');

    expect(AccountReconciliation::where('account_id', $fx['suspense']->id)->count())->toBe(0);
});

it('rechaza reconciliar movimientos que no cuadran, sin crear nada', function () {
    $fx = accountReconciliationHttpFixture();
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');
    $d2 = postSuspenseMovement($fx, $fx['suspense'], false, '300');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), [
        'lines' => [
            ['journal_detail_id' => $d1->id, 'amount' => '500'],
            ['journal_detail_id' => $d2->id, 'amount' => '300'],
        ],
    ])->assertSessionHasErrors('reconciliation');

    expect(AccountReconciliation::where('account_id', $fx['suspense']->id)->count())->toBe(0);
});

it('rechaza reconciliar un movimiento que tiene socio de negocio asociado, aunque la cuenta no lo exija', function () {
    $fx = accountReconciliationHttpFixture();
    // requires_business_partner=false en $fx['suspense'] — pero nada impide
    // que UNA línea puntual sí traiga socio (ver PostJournalService::post(),
    // esa exigencia es un mínimo, no una prohibición de lo contrario).
    $partner = BusinessPartner::factory()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $fx['suspense']->id, 'currency_id' => $fx['company']->local_currency_id,
    ]);

    $entry = app(PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new JournalLineInput($fx['suspense']->id, $fx['company']->local_currency_id, debit: 100, credit: 0, businessPartnerId: $partner->id),
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: 100),
        ],
    );
    $bpDetail = $entry->details->firstWhere('account_id', $fx['suspense']->id);
    $other = postSuspenseMovement($fx, $fx['suspense'], false, '100');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), [
        'lines' => [
            ['journal_detail_id' => $bpDetail->id, 'amount' => '100'],
            ['journal_detail_id' => $other->id, 'amount' => '100'],
        ],
    ])->assertSessionHasErrors('reconciliation');

    expect(AccountReconciliation::where('account_id', $fx['suspense']->id)->count())->toBe(0);
});

it('rechaza reconciliar movimientos de dos cuentas distintas', function () {
    $fx = accountReconciliationHttpFixture();
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');

    $other = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-002']);
    $entry2 = app(PostJournalService::class)->post(
        $fx['company'], $fx['add'], new DateTime(now()->format('Y-m-d')), new DateTime(now()->format('Y-m-d')),
        [
            new JournalLineInput($fx['cash']->id, $fx['company']->local_currency_id, debit: 0, credit: 500),
            new JournalLineInput($other->id, $fx['company']->local_currency_id, debit: 500, credit: 0),
        ],
    );
    $d2 = $entry2->details->firstWhere('account_id', $fx['cash']->id);

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), [
        'lines' => [
            ['journal_detail_id' => $d1->id, 'amount' => '500'],
            ['journal_detail_id' => $d2->id, 'amount' => '500'],
        ],
    ])->assertSessionHasErrors('reconciliation');

    expect(AccountReconciliation::where('account_id', $fx['suspense']->id)->count())->toBe(0);
});

it('rechaza reconciliar un movimiento sin saldo disponible (ya reconciliado del todo)', function () {
    $fx = accountReconciliationHttpFixture();
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');
    $d2 = postSuspenseMovement($fx, $fx['suspense'], false, '500');
    $d3 = postSuspenseMovement($fx, $fx['suspense'], true, '200');
    $d4 = postSuspenseMovement($fx, $fx['suspense'], false, '200');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), ['lines' => [
        ['journal_detail_id' => $d1->id, 'amount' => '500'],
        ['journal_detail_id' => $d2->id, 'amount' => '500'],
    ]])->assertSessionHasNoErrors();

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), ['lines' => [
        ['journal_detail_id' => $d1->id, 'amount' => '500'],
        ['journal_detail_id' => $d3->id, 'amount' => '200'],
    ]])->assertSessionHasErrors('reconciliation');

    // La segunda reconciliación válida (d3+d4) sigue disponible: la primera no bloqueó nada más que d1/d2.
    $this->post(route('account-reconciliation.store', $fx['suspense']->id), ['lines' => [
        ['journal_detail_id' => $d3->id, 'amount' => '200'],
        ['journal_detail_id' => $d4->id, 'amount' => '200'],
    ]])->assertSessionHasNoErrors();
});

it('deshacer una reconciliación libera los movimientos, sin tocar los asientos', function () {
    $fx = accountReconciliationHttpFixture();
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');
    $d2 = postSuspenseMovement($fx, $fx['suspense'], false, '500');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), ['lines' => [
        ['journal_detail_id' => $d1->id, 'amount' => '500'],
        ['journal_detail_id' => $d2->id, 'amount' => '500'],
    ]]);
    $reconciliation = AccountReconciliation::where('account_id', $fx['suspense']->id)->sole();

    $this->delete(route('account-reconciliation.destroy', $reconciliation->id))->assertSessionHasNoErrors();

    expect(AccountReconciliation::find($reconciliation->id))->toBeNull()
        ->and(JournalDetail::find($d1->id))->not->toBeNull()
        ->and(JournalDetail::find($d2->id))->not->toBeNull();

    $this->get(route('account-reconciliation.index', $fx['suspense']->id))
        ->assertInertia(fn ($page) => $page->has('unreconciled', 2));
});

it('contabiliza un traspaso ARR entre dos cuentas y crea el tipo de documento reservado de oficio', function () {
    $fx = accountReconciliationHttpFixture();
    $target = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-001']);

    $response = $this->post(route('account-reconciliation.transfer', $fx['suspense']->id), [
        'posting_date' => now()->format('Y-m-d'),
        'amount' => '750',
        'direction' => 'credit',
        'target_type' => 'account',
        'target_account_id' => $target->id,
        'currency_id' => $fx['company']->local_currency_id,
    ]);

    $response->assertRedirect(route('account-reconciliation.index', $fx['suspense']->id));

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    expect($entry->documentType->code)->toBe('ARR')
        ->and($entry->documentType->is_reconciliation_type)->toBeTrue()
        ->and($entry->details->firstWhere('account_id', $fx['suspense']->id)->credit_local)->toEqual('750.00')
        ->and($entry->details->firstWhere('account_id', $target->id)->debit_local)->toEqual('750.00');
});

it('un traspaso ARR contra un socio de negocio usa su cuenta de control', function () {
    $fx = accountReconciliationHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id,
    ]);

    $this->post(route('account-reconciliation.transfer', $fx['suspense']->id), [
        'posting_date' => now()->format('Y-m-d'),
        'amount' => '300',
        'direction' => 'debit',
        'target_type' => 'partner',
        'target_business_partner_id' => $partner->id,
        'currency_id' => $fx['company']->local_currency_id,
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->sole();
    $partnerDetail = $entry->details->firstWhere('account_id', $cxc->id);

    expect($partnerDetail->business_partner_id)->toBe($partner->id)
        ->and($partnerDetail->credit_local)->toEqual('300.00');
});

it('reutiliza el mismo tipo de documento ARR en un segundo traspaso, sin duplicarlo', function () {
    $fx = accountReconciliationHttpFixture();
    $target = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-001']);

    foreach (['100', '200'] as $amount) {
        $this->post(route('account-reconciliation.transfer', $fx['suspense']->id), [
            'posting_date' => now()->format('Y-m-d'),
            'amount' => $amount,
            'direction' => 'debit',
            'target_type' => 'account',
            'target_account_id' => $target->id,
            'currency_id' => $fx['company']->local_currency_id,
        ])->assertSessionHasNoErrors();
    }

    expect(DocumentType::where('company_id', $fx['company']->id)->where('code', 'ARR')->count())->toBe(1);
});

it('el tipo de documento ARR no aparece en el formulario manual de asientos', function () {
    $fx = accountReconciliationHttpFixture();
    $target = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-001']);

    $this->post(route('account-reconciliation.transfer', $fx['suspense']->id), [
        'posting_date' => now()->format('Y-m-d'),
        'amount' => '50',
        'direction' => 'debit',
        'target_type' => 'account',
        'target_account_id' => $target->id,
        'currency_id' => $fx['company']->local_currency_id,
    ]);

    $this->get(route('journal-entries.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documentTypes', fn ($types) => ! collect($types)->contains('code', 'ARR'))
        );
});

it('reclasifica un movimiento a otra cuenta y lo reconcilia de una vez', function () {
    $fx = accountReconciliationHttpFixture();
    $target = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-001']);
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');

    $response = $this->post(route('account-reconciliation.reclassify', $fx['suspense']->id), [
        'journal_detail_id' => $d1->id,
        'posting_date' => now()->format('Y-m-d'),
        'target_type' => 'account',
        'target_account_id' => $target->id,
    ]);

    $response->assertSessionHasNoErrors();

    $reconciliation = AccountReconciliation::where('account_id', $fx['suspense']->id)->sole();
    expect($reconciliation->lines)->toHaveCount(2)
        ->and($d1->fresh()->availableReconciliationAmount())->toEqual('0.00');

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->where('document_type_id', function ($q) use ($fx) {
        $q->select('id')->from('document_types')->where('company_id', $fx['company']->id)->where('code', 'ARR');
    })->sole();
    expect($entry->details->firstWhere('account_id', $target->id)->debit_local)->toEqual('500.00')
        ->and($entry->details->firstWhere('account_id', $fx['suspense']->id)->credit_local)->toEqual('500.00');

    $this->get(route('account-reconciliation.index', $fx['suspense']->id))
        ->assertInertia(fn ($page) => $page->has('unreconciled', 0));
});

it('un reclasificar hacia un socio de negocio usa su cuenta de control', function () {
    $fx = accountReconciliationHttpFixture();
    $cxc = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '1-01-02-01-001']);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $fx['company']->id, 'gl_account_id' => $cxc->id, 'currency_id' => $fx['company']->local_currency_id,
    ]);
    $d1 = postSuspenseMovement($fx, $fx['suspense'], false, '300');

    $this->post(route('account-reconciliation.reclassify', $fx['suspense']->id), [
        'journal_detail_id' => $d1->id,
        'posting_date' => now()->format('Y-m-d'),
        'target_type' => 'partner',
        'target_business_partner_id' => $partner->id,
    ])->assertSessionHasNoErrors();

    $entry = JournalEntry::withoutGlobalScope(CompanyScope::class)->where('document_type_id', function ($q) use ($fx) {
        $q->select('id')->from('document_types')->where('company_id', $fx['company']->id)->where('code', 'ARR');
    })->sole();
    $partnerDetail = $entry->details->firstWhere('account_id', $cxc->id);

    // $d1 acreditó suspense en 300 (postSuspenseMovement con primaryIsDebit
    // false): para cancelarlo, la contrapartida nueva debita suspense y
    // acredita al socio — el mismo neto económico que tenía el original.
    expect($partnerDetail->business_partner_id)->toBe($partner->id)
        ->and($partnerDetail->credit_local)->toEqual('300.00');
});

it('rechaza reclasificar un movimiento sin saldo disponible', function () {
    $fx = accountReconciliationHttpFixture();
    $target = ChartOfAccount::factory()->create(['company_id' => $fx['company']->id, 'code' => '5-01-01-01-001']);
    $d1 = postSuspenseMovement($fx, $fx['suspense'], true, '500');
    $d2 = postSuspenseMovement($fx, $fx['suspense'], false, '500');

    $this->post(route('account-reconciliation.store', $fx['suspense']->id), ['lines' => [
        ['journal_detail_id' => $d1->id, 'amount' => '500'],
        ['journal_detail_id' => $d2->id, 'amount' => '500'],
    ]])->assertSessionHasNoErrors();

    $this->post(route('account-reconciliation.reclassify', $fx['suspense']->id), [
        'journal_detail_id' => $d1->id,
        'posting_date' => now()->format('Y-m-d'),
        'target_type' => 'account',
        'target_account_id' => $target->id,
    ])->assertSessionHasErrors('reconciliation');
});

it('rechaza acceder a la reconciliación de una cuenta de otra compañía', function () {
    accountReconciliationHttpFixture();
    $companyB = Company::factory()->create();
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);

    $this->get(route('account-reconciliation.index', $accountB->id))->assertNotFound();
});
