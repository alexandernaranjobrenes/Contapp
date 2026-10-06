<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Models\ContiAction;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Lo que Conti guarda: preparar y confirmar
|--------------------------------------------------------------------------
|
| Conti prepara (API, con el pase del agente) y la persona confirma en
| CONTAPP (con su sesión). Nada se escribe al preparar; al confirmar se
| vuelve a revisar todo.
|
*/

function contiPartnerFixture(array $levels = ['business_partners.partners' => 'read_write']): array
{
    $f = contiUser($levels);
    $f['account'] = ChartOfAccount::factory()->create(['company_id' => $f['company']->id, 'code' => '1-01-02-001', 'description_es' => 'Clientes locales']);
    $f['headers'] = contiHeaders(contiToken($f['user'], $f['company']));

    return $f;
}

function contiPrepare(array $f, string $action, array $data)
{
    return test()->postJson('/api/conti/acciones', ['accion' => $action, 'datos' => $data], $f['headers']);
}

function partnersOf(Company $company)
{
    return BusinessPartner::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id);
}

it('preparar no guarda nada: deja la acción pendiente, con su resumen y el enlace para confirmar', function () {
    $f = contiPartnerFixture();

    $response = contiPrepare($f, 'crear_socio', [
        'codigo' => 'C-100', 'nombre' => 'Ferretería Central', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001',
    ])->assertCreated()
        ->assertJsonPath('estado', 'pendiente')
        ->assertJsonPath('resumen.titulo', 'Crear el socio C-100 — Ferretería Central');

    expect($response->json('enlace_para_confirmar'))->toBe(rtrim(config('app.url'), '/').'/conti/acciones/'.$response->json('id'))
        ->and(partnersOf($f['company'])->count())->toBe(0)
        ->and(ContiAction::first()->status)->toBe('pending');
});

it('al confirmar en CONTAPP se guarda con su usuario, y el agente ve el resultado', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', [
        'codigo' => 'C-100', 'nombre' => 'Ferretería Central', 'tipo' => 'ambos', 'cuenta_control' => '1-01-02-001', 'plazo_dias' => 30,
    ])->json('id');

    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid))->assertSessionHas('success', 'Socio C-100 creado.');

    $partner = partnersOf($f['company'])->first();
    expect($partner->name)->toBe('Ferretería Central')
        ->and($partner->type)->toBe('both')
        ->and($partner->payment_terms_days)->toBe(30)
        ->and($partner->gl_account_id)->toBe($f['account']->id)
        ->and($partner->partner_since->format('Y-m-d'))->toBe(now()->format('Y-m-d'));

    $this->getJson("/api/conti/acciones/{$uuid}", $f['headers'])
        ->assertJsonPath('estado', 'guardado')
        ->assertJsonPath('resultado.mensaje', 'Socio C-100 creado.');
});

it('confirmar dos veces guarda una sola', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])->json('id');

    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid));
    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid));

    expect(partnersOf($f['company'])->count())->toBe(1);
});

it('con solo Lectura no puede preparar nada para guardar', function () {
    $f = contiPartnerFixture(['business_partners.partners' => 'read']);

    contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])
        ->assertForbidden();

    expect(ContiAction::count())->toBe(0);
});

it('si al confirmar ya no tiene el permiso, no se guarda', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])->json('id');

    app(PermissionGrantService::class)->writeScreenLevels($f['company']->id, $f['user'], ['business_partners.partners' => 'read']);

    // Como lo manda la pantalla (Inertia): el 403 vuelve como mensaje.
    $this->actingAs($f['user'])
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('conti.actions.confirm', $uuid))
        ->assertSessionHas('error', 'Ya no tenés permiso para esto: hace falta Lectura y escritura en «Socios de negocio → Socios de negocio».');

    expect(partnersOf($f['company'])->count())->toBe(0)
        ->and(ContiAction::first()->status)->toBe('pending');
});

it('valida lo que manda el agente y dice qué está mal', function () {
    $f = contiPartnerFixture();

    contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'tipo' => 'cliente', 'cuenta_control' => '9-99'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nombre']);

    contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '9-99'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cuenta_control']);
});

it('otra persona no ve ni confirma lo que Conti preparó para alguien más', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])->json('id');
    ['user' => $other] = contiUser(['business_partners.partners' => 'read_write'], $f['company']);

    $this->actingAs($other)->get(route('conti.actions.show', $uuid))->assertNotFound();
    $this->actingAs($other)->post(route('conti.actions.confirm', $uuid))->assertNotFound();

    expect(partnersOf($f['company'])->count())->toBe(0);
});

it('vencida, ya no se puede confirmar', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])->json('id');
    ContiAction::query()->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid));

    expect(partnersOf($f['company'])->count())->toBe(0);
    $this->getJson("/api/conti/acciones/{$uuid}", $f['headers'])->assertJsonPath('estado', 'vencido');
});

it('descartada, no se guarda', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])->json('id');

    $this->actingAs($f['user'])->post(route('conti.actions.discard', $uuid));
    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid));

    expect(partnersOf($f['company'])->count())->toBe(0)
        ->and(ContiAction::first()->status)->toBe('discarded');
});

it('si entre preparar y confirmar algo cambió y ya no es válido, no se guarda y se dice por qué', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])->json('id');
    BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'code' => 'C-100']);

    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid))->assertSessionHas('error');

    expect(ContiAction::first()->status)->toBe('failed')
        ->and(ContiAction::first()->error)->toContain('ya está en uso');
});

it('la pantalla de confirmación muestra el resumen', function () {
    $f = contiPartnerFixture();
    $uuid = contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'Ferretería Central', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])->json('id');

    $this->actingAs($f['user'])->get(route('conti.actions.show', $uuid))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Conti/Action')
            ->where('action.status', 'pending')
            ->where('action.summary.titulo', 'Crear el socio C-100 — Ferretería Central')
            ->where('action.other_company', false));
});

it('con la licencia vencida se consulta, pero no se prepara nada para guardar', function () {
    $f = contiPartnerFixture();
    $license = License::factory()->expired()->create(['superuser_id' => $f['user']->id]);
    $f['company']->update(['license_id' => $license->id]);

    $this->getJson('/api/conti/datos/socios', $f['headers'])->assertOk();
    contiPrepare($f, 'crear_socio', ['codigo' => 'C-100', 'nombre' => 'X', 'tipo' => 'cliente', 'cuenta_control' => '1-01-02-001'])
        ->assertForbidden();
});

/** Una compañía en la que se puede contabilizar hoy. */
function contiJournalFixture(): array
{
    $f = contiUser(['accounting.journal_entries' => 'read_write', 'business_partners.partners' => 'read_write']);
    $company = $f['company'];

    ExchangeRate::factory()->create(['company_id' => $company->id, 'currency_id' => $company->foreign_currency_id, 'rate_date' => now()->format('Y-m-d'), 'rate' => '520.000000']);
    $year = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $year->id, 'period_number' => (int) now()->format('n'), 'status' => 'open',
        'start_date' => now()->startOfMonth()->format('Y-m-d'), 'end_date' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    $f['cash'] = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-001', 'description_es' => 'Caja']);
    $f['income'] = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '4-01-001', 'account_type' => 'income', 'normal_balance' => 'credit']);
    $f['receivable'] = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-02-001', 'requires_business_partner' => true]);
    $f['type'] = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB']);
    $f['headers'] = contiHeaders(contiToken($f['user'], $company));

    return $f;
}

it('un asiento preliminar: se prepara, se confirma y queda como preliminar para revisar', function () {
    $f = contiJournalFixture();

    $uuid = contiPrepare($f, 'crear_asiento', [
        'tipo_documento' => 'TRB',
        'descripcion' => 'Venta de contado',
        'lineas' => [
            ['cuenta' => '1-01-001', 'debito' => 1500],
            ['cuenta' => '4-01-001', 'credito' => 1500],
        ],
    ])->assertCreated()->json('id');

    expect(JournalEntry::withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid));

    $entry = JournalEntry::withoutGlobalScopes()->first();
    expect($entry->status)->toBe('draft')
        ->and($entry->description)->toBe('Venta de contado')
        ->and($entry->created_by)->toBe($f['user']->id)
        ->and($entry->details()->count())->toBe(2);
});

it('la prueba en seco frena lo que el servicio rechazaría, sin dejar rastro', function () {
    $f = contiJournalFixture();

    contiPrepare($f, 'crear_asiento', [
        'tipo_documento' => 'TRB',
        'modo' => 'contabilizar',
        'fecha_contabilizacion' => now()->addYears(3)->format('Y-m-d'),
        'lineas' => [['cuenta' => '1-01-001', 'debito' => 100], ['cuenta' => '4-01-001', 'credito' => 100]],
    ])->assertStatus(422)->assertJsonValidationErrors(['accion']);

    contiPrepare($f, 'crear_asiento', [
        'tipo_documento' => 'TRB',
        'lineas' => [['cuenta' => '1-01-001', 'debito' => 100], ['cuenta' => '4-01-001', 'credito' => 90]],
    ])->assertStatus(422)->assertJsonValidationErrors(['lineas']);

    expect(JournalEntry::withoutGlobalScopes()->count())->toBe(0)
        ->and(ContiAction::count())->toBe(0);
});

it('una cuenta que exige socio se usa a través del socio', function () {
    $f = contiJournalFixture();
    BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'code' => 'C-001', 'gl_account_id' => $f['receivable']->id]);

    contiPrepare($f, 'crear_asiento', [
        'tipo_documento' => 'TRB',
        'lineas' => [['cuenta' => '1-02-001', 'debito' => 100], ['cuenta' => '4-01-001', 'credito' => 100]],
    ])->assertStatus(422)->assertJsonValidationErrors(['lineas.0.cuenta']);

    contiPrepare($f, 'crear_asiento', [
        'tipo_documento' => 'TRB',
        'modo' => 'contabilizar',
        'lineas' => [['socio' => 'C-001', 'debito' => 100, 'vencimiento' => now()->addDays(30)->format('Y-m-d')], ['cuenta' => '4-01-001', 'credito' => 100]],
    ])->assertCreated();
});

it('aplicar un cobro a una partida abierta la rebaja con su asiento', function () {
    $f = contiJournalFixture();
    $client = BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'code' => 'C-001', 'gl_account_id' => $f['receivable']->id]);

    app(CurrentCompany::class)->set($f['company']->id);
    app(PostJournalService::class)->post($f['company'], $f['type'], now(), now(), [
        new JournalLineInput($f['receivable']->id, $f['company']->local_currency_id, debit: 1000, credit: 0, businessPartnerId: $client->id, dueDate: now()->addDays(30)->format('Y-m-d'), opensItem: true),
        new JournalLineInput($f['income']->id, $f['company']->local_currency_id, debit: 0, credit: 1000),
    ], 'Venta a crédito', $f['user']->id);
    app(CurrentCompany::class)->clear();

    $item = BpOpenItem::first();

    $uuid = contiPrepare($f, 'aplicar_pago', ['partida' => $item->id, 'cuenta_pago' => '1-01-001', 'monto' => 400])
        ->assertCreated()->json('id');

    expect((string) $item->fresh()->balance)->toBe('1000.00');

    $this->actingAs($f['user'])->post(route('conti.actions.confirm', $uuid))->assertSessionHas('success');

    expect((string) $item->fresh()->balance)->toBe('600.00')
        ->and(JournalEntry::withoutGlobalScopes()->count())->toBe(2);
});
