<?php

use App\Domains\Accounting\Services\IncomeStatementService;
use App\Domains\Core\Dashboard\DashboardService;
use App\Domains\Core\Dashboard\DashboardWidgetCatalog;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DashboardLayout;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Models\ScreenPermission;
use App\Domains\Core\Support\ScreenCatalog;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| El Panel (CLAUDE.md secc. 44)
|--------------------------------------------------------------------------
|
| Cada persona ve solo los elementos de las pantallas a las que tiene
| acceso; lo cambian el Superusuario y los Administradores, nunca con algo
| que ellos mismos no ven, y sin pasar del máximo.
*/

/** Una persona de la compañía, con su rol y solo las pantallas que se le den. */
function panelPerson(Company $company, string $role, array $screens = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'status' => 'active', 'is_super_admin' => $role === 'super_admin']);
    $company->users()->attach($user->id, ['is_default' => true]);

    if (in_array($role, ['admin', 'user'], true)) {
        $roleModel = Role::firstOrCreate(
            ['company_id' => null, 'type' => $role],
            ['name' => $role === 'admin' ? 'Administrador' : 'Usuario', 'can_grant_permissions' => $role === 'admin'],
        );
        $user->userRoles()->create(['role_id' => $roleModel->id, 'company_id' => $company->id]);
    }

    foreach ($screens as $screen => $level) {
        ScreenPermission::create(['company_id' => $company->id, 'user_id' => $user->id, 'screen' => $screen, 'access_level' => $level]);
    }

    return $user;
}

function panelKeys($response): array
{
    return collect($response->viewData('page')['props']['widgets'])->pluck('key')->all();
}

function savePanel(User $user, Company $company, array $keys)
{
    return test()->actingAs($user)->withSession(['current_company_id' => $company->id])
        ->put(route('dashboard.layout.update'), ['widgets' => $keys]);
}

it('cada elemento del catálogo declara pantallas que existen, y las claves no se repiten', function () {
    $widgets = DashboardWidgetCatalog::all();

    expect(count($widgets))->toBe(count(array_unique(array_keys($widgets))));

    foreach ($widgets as $widget) {
        foreach ($widget->screens() as $screen) {
            expect(ScreenCatalog::exists($screen))->toBeTrue("{$widget->key()} pide la pantalla {$screen}, que no existe");
        }

        expect(in_array($widget->kind(), ['stat', 'columns', 'bars', 'line', 'list', 'meter'], true))->toBeTrue()
            ->and(in_array($widget->size(), ['small', 'medium', 'large'], true))->toBeTrue();
    }

    expect(count(DashboardWidgetCatalog::DEFAULT_LAYOUT))->toBeLessThanOrEqual(DashboardWidgetCatalog::MAX_WIDGETS);
    foreach (DashboardWidgetCatalog::DEFAULT_LAYOUT as $key) {
        expect(DashboardWidgetCatalog::find($key))->not->toBeNull();
    }
});

it('sin personalizar, el Superusuario ve el Panel de siempre, y cada elemento se calcula sin error en una compañía vacía', function () {
    $company = Company::factory()->create();
    $owner = panelPerson($company, 'super_admin');

    $response = $this->actingAs($owner)->withSession(['current_company_id' => $company->id])->get(route('dashboard'))->assertOk();

    expect(panelKeys($response))->toBe(DashboardWidgetCatalog::DEFAULT_LAYOUT)
        ->and(collect($response->viewData('page')['props']['widgets'])->where('error', true))->toBeEmpty()
        ->and($response->viewData('page')['props']['canEdit'])->toBeTrue();
});

it('quien no tiene acceso a una pantalla no ve sus elementos, ni puede cambiar el Panel', function () {
    $company = Company::factory()->create();
    $clerk = panelPerson($company, 'user', ['accounting.journal_entries' => 'read_write']);

    $response = $this->actingAs($clerk)->withSession(['current_company_id' => $company->id])->get(route('dashboard'))->assertOk();

    // Del Panel de siempre, solo los dos de Registros.
    expect(panelKeys($response))->toBe(['draft_entries', 'recent_entries'])
        ->and($response->viewData('page')['props']['canEdit'])->toBeFalse()
        ->and($response->viewData('page')['props']['editor'])->toBeNull();

    savePanel($clerk, $company, ['recent_entries'])->assertForbidden();
});

it('un gráfico de Inventario que agregó el Superusuario no lo ve quien no entra a Inventario', function () {
    $company = Company::factory()->create();
    $owner = panelPerson($company, 'super_admin');
    $seller = panelPerson($company, 'user', ['billing.sales_documents' => 'read']);
    $storekeeper = panelPerson($company, 'user', ['reports.inventory_valuation' => 'read']);

    savePanel($owner, $company, ['sales_by_month', 'inventory_by_warehouse'])->assertRedirect(route('dashboard'));

    $this->actingAs($seller)->withSession(['current_company_id' => $company->id]);
    expect(panelKeys($this->get(route('dashboard'))))->toBe(['sales_by_month']);

    $this->actingAs($storekeeper)->withSession(['current_company_id' => $company->id]);
    expect(panelKeys($this->get(route('dashboard'))))->toBe(['inventory_by_warehouse']);
});

it('un Administrador solo puede agregar lo que ve, y lo que no ve queda donde estaba', function () {
    $company = Company::factory()->create();
    $owner = panelPerson($company, 'super_admin');
    $admin = panelPerson($company, 'admin', [
        'accounting.journal_entries' => 'read_write',
        'billing.sales_documents' => 'read',
    ]);

    savePanel($owner, $company, ['month_result', 'recent_entries', 'payroll_cost', 'sales_by_month'])->assertRedirect();

    $response = $this->actingAs($admin)->withSession(['current_company_id' => $company->id])->get(route('dashboard'));
    $editor = $response->viewData('page')['props']['editor'];

    // El catálogo es solo de lo suyo (sin Planillas ni reportes contables), y
    // sabe que hay dos que no ve.
    $catalog = collect($editor['catalog'])->pluck('key');
    expect($catalog)->toContain('draft_entries', 'recent_entries', 'sales_by_month')
        ->and($catalog)->not->toContain('payroll_cost')
        ->and($catalog)->not->toContain('month_result')
        ->and($catalog)->not->toContain('inventory_by_warehouse')
        ->and($editor['hidden'])->toBe(2);

    // No puede meter uno de Planillas.
    savePanel($admin, $company, ['sales_by_month', 'recent_entries', 'payroll_cost'])
        ->assertSessionHasErrors(['widgets' => '«Costo de planilla» es de una pantalla a la que no tenés acceso: no lo podés agregar.']);

    // Reordena y agrega lo suyo: los dos que no ve siguen en su lugar.
    savePanel($admin, $company, ['draft_entries', 'sales_by_month', 'recent_entries'])->assertRedirect();

    $layout = DashboardLayout::where('company_id', $company->id)->sole()->widgets;
    expect($layout)->toBe(['month_result', 'draft_entries', 'payroll_cost', 'sales_by_month', 'recent_entries']);
});

it('no pasa del máximo, contando los que quien edita no ve', function () {
    $company = Company::factory()->create();
    $owner = panelPerson($company, 'super_admin');
    $keys = array_slice(array_keys(DashboardWidgetCatalog::all()), 0, DashboardWidgetCatalog::MAX_WIDGETS + 1);

    savePanel($owner, $company, $keys)->assertSessionHasErrors('widgets');
    expect(DashboardLayout::count())->toBe(0);

    // Lleno, casi todo con lo que el Administrador no ve: aunque él vea uno
    // solo, no le queda lugar para otro.
    $admin = panelPerson($company, 'admin', ['accounting.journal_entries' => 'read_write']);
    $hidden = collect(array_keys(DashboardWidgetCatalog::all()))
        ->reject(fn ($k) => in_array($k, ['recent_entries', 'draft_entries', 'conti_usage', 'einvoice_attention'], true))
        ->take(DashboardWidgetCatalog::MAX_WIDGETS - 1)->values()->all();
    savePanel($owner, $company, [...$hidden, 'draft_entries'])->assertRedirect();

    savePanel($admin, $company, ['draft_entries', 'recent_entries'])
        ->assertSessionHasErrors(['widgets' => 'El Panel lleva hasta '.DashboardWidgetCatalog::MAX_WIDGETS.' elementos. Quitá alguno para agregar otro.']);
});

it('rechaza claves que no existen o repetidas, y anota el cambio en la Bitácora', function () {
    $company = Company::factory()->create();
    $owner = panelPerson($company, 'super_admin');

    savePanel($owner, $company, ['no_existe'])->assertSessionHasErrors('widgets');
    savePanel($owner, $company, ['recent_entries', 'recent_entries'])->assertSessionHasErrors('widgets');

    savePanel($owner, $company, ['recent_entries'])->assertRedirect();

    $log = AuditLog::where('action', 'company.dashboard_updated')->sole();
    expect($log->new_values)->toBe(['widgets' => ['recent_entries']])
        ->and($log->old_values)->toBe(['widgets' => DashboardWidgetCatalog::DEFAULT_LAYOUT]);

    // Un Panel vacío es una elección: no vuelve al de siempre.
    savePanel($owner, $company, [])->assertRedirect();
    $this->actingAs($owner)->withSession(['current_company_id' => $company->id]);
    expect(panelKeys($this->get(route('dashboard'))))->toBe([]);
});

it('lo que depende de la licencia o de la compañía no se ve sin ella: Conti y la facturación electrónica', function () {
    $license = License::factory()->create(['ai_enabled' => false]);
    $company = Company::factory()->create(['license_id' => $license->id]);
    $owner = panelPerson($company, 'super_admin');
    $license->forceFill(['superuser_id' => $owner->id])->save();

    $response = $this->actingAs($owner)->withSession(['current_company_id' => $company->id])->get(route('dashboard'));
    $catalog = collect($response->viewData('page')['props']['editor']['catalog'])->pluck('key');

    expect($catalog)->not->toContain('conti_usage')
        ->and($catalog)->not->toContain('einvoice_attention');

    savePanel($owner, $company, ['conti_usage'])->assertSessionHasErrors('widgets');
});

it('los números salen igual que en los reportes', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00'));

    $f = salesFixture();
    $sale = postSale($f); // 10 u × ₡2.500, IVA 13 %, a crédito
    $owner = panelPerson($f['company'], 'super_admin');

    savePanel($owner, $f['company'], [
        'month_result', 'income_expenses', 'sales_by_month', 'receivables_aging', 'top_debtors',
        'inventory_by_warehouse', 'recent_entries', 'exchange_rate', 'cash_balances', 'payables_aging',
        'below_minimum', 'payroll_cost',
    ])->assertRedirect();

    $widgets = collect(app(DashboardService::class)->panel($owner, $f['company']))->keyBy('key');

    expect($widgets->where('error', true))->toBeEmpty();

    // El resultado del mes es el del estado de resultados del mes.
    $statement = app(IncomeStatementService::class)->build($f['company'], '2026-10-01', '2026-10-15');
    expect($widgets['month_result']['data']['value'])->toBe(round((float) $statement->netProfit, 2))
        ->and($widgets['income_expenses']['data']['points'][11]['values']['income'])->toBe(round((float) $statement->salesTotal + (float) $statement->otherIncomeTotal, 2));

    // Las ventas del mes, la venta neta del comprobante.
    expect($widgets['sales_by_month']['data']['points'][11]['values']['sales'])->toBe(round((float) $sale->total_net_sale, 2));

    // Lo que debe el cliente: la factura con su IVA, vigente.
    $receivables = $widgets['receivables_aging']['data'];
    expect($receivables['total']['value'])->toBe(round((float) $sale->total_document, 2))
        ->and($receivables['rows'][0]['value'])->toBe(round((float) $sale->total_document, 2))
        ->and($widgets['top_debtors']['data']['rows'][0]['sublabel'])->toBe('C-001');

    // El inventario que quedó: 90 u a ₡1.000.
    expect($widgets['inventory_by_warehouse']['data']['total']['value'])->toBe(90000.0);

    Carbon::setTestNow();
});
