<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\ScreenPermission;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Cobros y pagos, aparte de la ficha del socio (CLAUDE.md secc. 36)
|--------------------------------------------------------------------------
|
| Separación de funciones: quien crea o edita proveedores no tiene por qué
| ser quien les aplica los pagos. Son dos pantallas con su propio permiso.
|
*/

function openItemsScreenFixture(): array
{
    ['company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    app(CurrentCompany::class)->set($company->id);

    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-02-01-001']);
    $sales = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-01-01-01-001']);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'code' => 'CLI-0001', 'name' => 'Ferretería El Clavo', 'type' => 'client',
        'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);

    return compact('company', 'cxc', 'sales', 'partner');
}

function openItemsScreenUser(Company $company, array $levels): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'status' => 'active']);
    $company->users()->attach($user->id, ['is_default' => true]);
    app(PermissionGrantService::class)->writeScreenLevels($company->id, $user, $levels);

    return $user;
}

/** Una factura a crédito: deja una partida abierta del socio. */
function openItemsScreenInvoice(array $f): void
{
    $year = FiscalYear::factory()->create(['company_id' => $f['company']->id, 'year' => 2026]);
    FiscalPeriod::factory()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open']);
    $fve = DocumentType::factory()->create(['company_id' => $f['company']->id, 'code' => 'FVE', 'origin_module' => 'ventas']);
    ExchangeRate::factory()->create(['company_id' => $f['company']->id, 'currency_id' => $f['company']->foreign_currency_id, 'rate_date' => '2026-01-01', 'rate' => '520.000000']);
    $currency = $f['company']->local_currency_id;

    app(PostJournalService::class)->post($f['company'], $fve, new DateTime('2026-01-05'), new DateTime('2026-01-05'), [
        new JournalLineInput($f['cxc']->id, $currency, debit: 1500, credit: 0, businessPartnerId: $f['partner']->id, dueDate: '2026-02-04', opensItem: true),
        new JournalLineInput($f['sales']->id, $currency, debit: 0, credit: 1500),
    ], 'Factura a crédito');
}

it('con solo la ficha de socios no se ven ni se aplican cobros y pagos', function () {
    $f = openItemsScreenFixture();
    $clerk = openItemsScreenUser($f['company'], ['business_partners.partners' => 'read_write']);
    $this->actingAs($clerk);

    $this->get(route('business-partners.index'))->assertOk();
    $this->get(route('open-items.index'))->assertForbidden();
    $this->get(route('business-partners.open-items', $f['partner']->id))->assertForbidden();
    $this->post(route('business-partners.open-items.reconcile', $f['partner']->id), ['open_item_ids' => []])->assertForbidden();

    // Al crear un socio, vuelve a la lista: sus partidas no le tocan.
    $this->post(route('business-partners.store'), [
        'code' => 'CLI-0002', 'name' => 'Pulpería La Esquina', 'type' => 'client',
        'gl_account_id' => $f['cxc']->id, 'currency_id' => $f['company']->local_currency_id,
    ])->assertRedirect(route('business-partners.index'));
});

it('quien tiene Cobros y pagos ve las partidas de todos, y la ficha del socio solo para consultar', function () {
    $f = openItemsScreenFixture();
    openItemsScreenInvoice($f);
    $cashier = openItemsScreenUser($f['company'], ['business_partners.open_items' => 'read_write', 'business_partners.partners' => 'read']);
    $this->actingAs($cashier);

    $this->get(route('open-items.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('BusinessPartners/OpenItemsOverview')
        ->has('partners', 1)
        ->where('partners.0.code', 'CLI-0001')
        ->where('partners.0.balances.0.items', 1)
        ->where('partners.0.balances.0.balance', fn ($balance) => (float) $balance === 1500.0)
        ->where('partners.0.balances.0.next_due', '2026-02-04'));

    $this->get(route('business-partners.open-items', $f['partner']->id))->assertOk();

    $this->put(route('business-partners.update', $f['partner']->id), [
        'code' => 'CLI-0001', 'name' => 'Otro nombre', 'type' => 'client',
        'gl_account_id' => $f['cxc']->id, 'currency_id' => $f['company']->local_currency_id,
    ])->assertForbidden();
});

it('al separar la pantalla, quien tenía la de socios conserva el mismo nivel en Cobros y pagos', function () {
    $f = openItemsScreenFixture();
    $before = openItemsScreenUser($f['company'], ['business_partners.partners' => 'read']);

    (require database_path('migrations/2026_10_14_110000_split_business_partner_open_items_screen.php'))->up();

    expect(ScreenPermission::where('user_id', $before->id)->pluck('access_level', 'screen')->all())
        ->toBe(['business_partners.partners' => 'read', 'business_partners.open_items' => 'read']);
});
