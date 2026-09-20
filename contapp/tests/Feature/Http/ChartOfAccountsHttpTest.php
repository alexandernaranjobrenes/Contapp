<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\ChartOfAccountTemplateExporter;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * @param  array<int,array<int,mixed>>  $rows
 */
function makeChartOfAccountsXlsx(array $rows, ?array $headers = null): UploadedFile
{
    $headers ??= ChartOfAccountTemplateExporter::HEADERS;

    $path = tempnam(sys_get_temp_dir(), 'coa_import_').'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('solo lista las cuentas de la compañía activa del usuario autenticado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $accountA = ChartOfAccount::factory()->create(['company_id' => $companyA->id, 'code' => '1-01-01-01-001']);
    ChartOfAccount::factory()->create(['company_id' => $companyB->id, 'code' => '1-01-01-01-002']);

    logInAsCompanyUser($companyA);

    $this->get(route('chart-of-accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ChartOfAccounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.code', $accountA->code)
        );
});

it('crea una cuenta con sus atributos especiales y deriva la naturaleza del tipo', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('chart-of-accounts.store'), [
        'code' => '1-01-02-01-001',
        'description_es' => 'Cuentas por cobrar clientes',
        'account_type' => 'asset',
        'currency_mode' => 'local',
        'tax_classification' => 'none',
        'accepts_posting' => true,
        'requires_business_partner' => true,
        'is_cash_account' => false,
        'requires_cost_center' => false,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $account = ChartOfAccount::where('company_id', $company->id)->where('code', '1-01-02-01-001')->sole();

    expect($account->normal_balance)->toBe('debit')
        ->and($account->requires_business_partner)->toBeTrue();
});

it('el índice trae las tarifas de IVA disponibles para vincular cuentas', function () {
    logInAsCompanyUser();
    $rate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00']);

    $this->get(route('chart-of-accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ChartOfAccounts/Index')
            ->has('taxRates', 1)
            ->where('taxRates.0.id', $rate->id)
        );
});

it('crea una cuenta vinculada a una tarifa de IVA (IVA Soportado)', function () {
    ['company' => $company] = logInAsCompanyUser();
    $rate = TaxRate::factory()->create(['code' => 'IVA-13', 'percentage' => '13.00']);

    $this->post(route('chart-of-accounts.store'), [
        'code' => '1-01-04-01-001',
        'description_es' => 'IVA Soportado 13%',
        'account_type' => 'asset',
        'currency_mode' => 'local',
        'tax_classification' => 'iva_soportado',
        'tax_rate_id' => $rate->id,
    ])->assertSessionHasNoErrors();

    $account = ChartOfAccount::where('company_id', $company->id)->where('code', '1-01-04-01-001')->sole();

    expect($account->tax_rate_id)->toBe($rate->id);
});

it('rechaza vincular una cuenta a una tarifa de IVA que no existe', function () {
    logInAsCompanyUser();

    $this->post(route('chart-of-accounts.store'), [
        'code' => '1-01-04-01-001',
        'description_es' => 'IVA Soportado 13%',
        'account_type' => 'asset',
        'currency_mode' => 'local',
        'tax_classification' => 'iva_soportado',
        'tax_rate_id' => 999999,
    ])->assertSessionHasErrors('tax_rate_id');
});

it('rechaza un código de cuenta duplicado dentro de la misma compañía', function () {
    ['company' => $company] = logInAsCompanyUser();
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);

    $this->post(route('chart-of-accounts.store'), [
        'code' => '1-01-01-01-001',
        'description_es' => 'Otra cuenta',
        'account_type' => 'asset',
        'currency_mode' => 'local',
        'tax_classification' => 'none',
    ])->assertSessionHasErrors('code');
});

it('actualiza una cuenta y le puede quitar/poner atributos especiales', function () {
    ['company' => $company] = logInAsCompanyUser();
    $account = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-01-01-001', 'account_type' => 'asset', 'is_cash_account' => false,
    ]);

    $this->put(route('chart-of-accounts.update', $account->id), [
        'code' => $account->code,
        'description_es' => 'Caja general',
        'account_type' => 'asset',
        'currency_mode' => 'local',
        'tax_classification' => 'none',
        'is_cash_account' => true,
    ])->assertSessionHasNoErrors();

    expect($account->fresh()->is_cash_account)->toBeTrue();
});

it('rechaza actualizar una cuenta de otra compañía', function () {
    $companyB = Company::factory()->create();
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id, 'code' => '1-01-01-01-001']);

    logInAsCompanyUser();

    $this->put(route('chart-of-accounts.update', $accountB->id), [
        'code' => $accountB->code,
        'description_es' => 'Intento ajeno',
        'account_type' => 'asset',
        'currency_mode' => 'local',
        'tax_classification' => 'none',
    ])->assertNotFound();
});

it('elimina una cuenta sin movimientos', function () {
    ['company' => $company] = logInAsCompanyUser();
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);

    $this->delete(route('chart-of-accounts.destroy', $account->id))->assertSessionHasNoErrors();

    expect(ChartOfAccount::find($account->id))->toBeNull();
});

it('rechaza eliminar una cuenta que ya tiene movimientos contabilizados', function () {
    ['company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
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
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 100, credit: 0),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $this->delete(route('chart-of-accounts.destroy', $cash->id))->assertSessionHasErrors('account');

    expect(ChartOfAccount::find($cash->id))->not->toBeNull();
});

it('descarga la plantilla xlsx con el catálogo de la compañía activa', function () {
    ['company' => $company] = logInAsCompanyUser();
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);

    $response = $this->get(route('chart-of-accounts.template'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});

it('importa cuentas nuevas desde un xlsx y deriva sus atributos', function () {
    ['company' => $company] = logInAsCompanyUser();

    $file = makeChartOfAccountsXlsx([
        ['1-01-02-01-001', 'Cuentas por cobrar clientes', 'Accounts receivable', 'Activos', 'Local', 'Ninguno', 'Sí', 'Sí', 'No', 'No', 'Sí'],
        ['6-01-01-01-001', 'Gastos de operación', '', 'Gastos', 'Local', 'Compras', 'Sí', 'No', 'No', 'No', 'Sí'],
    ]);

    $this->post(route('chart-of-accounts.import'), ['file' => $file])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $cxc = ChartOfAccount::where('company_id', $company->id)->where('code', '1-01-02-01-001')->sole();

    expect($cxc->account_type)->toBe('asset')
        ->and($cxc->normal_balance)->toBe('debit')
        ->and($cxc->description_en)->toBe('Accounts receivable')
        ->and($cxc->requires_business_partner)->toBeTrue();

    $gastos = ChartOfAccount::where('company_id', $company->id)->where('code', '6-01-01-01-001')->sole();

    expect($gastos->account_type)->toBe('expense')
        ->and($gastos->normal_balance)->toBe('debit')
        ->and($gastos->tax_classification)->toBe('purchases');
});

it('actualiza una cuenta existente si el código ya existe en el archivo (upsert)', function () {
    ['company' => $company] = logInAsCompanyUser();
    $existing = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '1-01-01-01-001',
        'description_es' => 'Nombre viejo', 'is_cash_account' => false,
    ]);

    $file = makeChartOfAccountsXlsx([
        ['1-01-01-01-001', 'Caja general', '', 'Activos', 'Local', 'Ninguno', 'Sí', 'No', 'Sí', 'No', 'Sí'],
    ]);

    $this->post(route('chart-of-accounts.import'), ['file' => $file])->assertSessionHasNoErrors();

    expect(ChartOfAccount::where('company_id', $company->id)->count())->toBe(1);

    $existing->refresh();
    expect($existing->description_es)->toBe('Caja general')
        ->and($existing->is_cash_account)->toBeTrue();
});

it('no importa ninguna fila si el archivo tiene al menos un error (todo o nada)', function () {
    ['company' => $company] = logInAsCompanyUser();

    $file = makeChartOfAccountsXlsx([
        ['1-01-01-01-001', 'Caja general', '', 'Activos', 'Local', 'Ninguno', 'Sí', 'No', 'Sí', 'No', 'Sí'],
        ['1-01-01-01-002', 'Cuenta con tipo inválido', '', 'NoExiste', 'Local', 'Ninguno', 'Sí', 'No', 'No', 'No', 'Sí'],
    ]);

    $this->post(route('chart-of-accounts.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'Fila 3')));

    expect(ChartOfAccount::where('company_id', $company->id)->count())->toBe(0);
});

it('rechaza códigos duplicados dentro del mismo archivo', function () {
    ['company' => $company] = logInAsCompanyUser();

    $file = makeChartOfAccountsXlsx([
        ['1-01-01-01-001', 'Caja general', '', 'Activos', 'Local', 'Ninguno', 'Sí', 'No', 'Sí', 'No', 'Sí'],
        ['1-01-01-01-001', 'Caja general otra vez', '', 'Activos', 'Local', 'Ninguno', 'Sí', 'No', 'Sí', 'No', 'Sí'],
    ]);

    $this->post(route('chart-of-accounts.import'), ['file' => $file])
        ->assertSessionHas('importErrors', fn ($errors) => collect($errors)->contains(fn ($e) => str_contains($e, 'repetido')));

    expect(ChartOfAccount::where('company_id', $company->id)->count())->toBe(0);
});

it('el upsert de importación respeta la compañía activa y no toca cuentas de otra compañía', function () {
    $companyB = Company::factory()->create();
    ChartOfAccount::factory()->create([
        'company_id' => $companyB->id, 'code' => '1-01-01-01-001', 'description_es' => 'Cuenta de otra empresa',
    ]);

    ['company' => $companyA] = logInAsCompanyUser();

    $file = makeChartOfAccountsXlsx([
        ['1-01-01-01-001', 'Caja general A', '', 'Activos', 'Local', 'Ninguno', 'Sí', 'No', 'Sí', 'No', 'Sí'],
    ]);

    $this->post(route('chart-of-accounts.import'), ['file' => $file])->assertSessionHasNoErrors();

    $accountA = ChartOfAccount::where('company_id', $companyA->id)->where('code', '1-01-01-01-001')->sole();
    expect($accountA->description_es)->toBe('Caja general A');

    $stillB = DB::table('chart_of_accounts')->where('company_id', $companyB->id)->where('code', '1-01-01-01-001')->value('description_es');
    expect($stillB)->toBe('Cuenta de otra empresa');
});
