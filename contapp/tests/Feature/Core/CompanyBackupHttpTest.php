<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Backup\BackupImporter;
use App\Domains\Core\Backup\CompanyBackupService;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyBackup;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Models\License;
use App\Jobs\InspectCompanyBackupJob;
use App\Models\User;
use App\Notifications\CompanyBackupExportedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Respaldos: las pantallas (CLAUDE.md secc. 37)
|--------------------------------------------------------------------------
|
| Solo el Superusuario (de su licencia) y el backoffice (de cualquiera).
| Antes de exportar, el resumen; para exportar e importar, la contraseña de
| la cuenta. Importar crea una compañía nueva, dentro del cupo.
|
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
});

/** Una compañía con licencia, su Superusuario (con sesión) y un asiento. */
function httpBackupFixture(): array
{
    $superuser = User::factory()->create(['is_super_admin' => true, 'email' => 'dueno@example.com', 'name' => 'Dueño']);
    $license = License::factory()->create(['superuser_id' => $superuser->id, 'max_companies' => 3]);
    $company = Company::factory()->create(['license_id' => $license->id, 'legal_name' => 'Panadería La Espiga S.A.', 'trade_name' => 'Panadería La Espiga', 'tax_id' => '3-101-555555']);
    $company->users()->attach($superuser->id, ['is_default' => true]);
    $superuser->update(['default_company_id' => $company->id]);

    ExchangeRate::factory()->create(['company_id' => $company->id, 'currency_id' => $company->foreign_currency_id, 'rate_date' => '2026-01-01', 'rate' => '510.000000']);
    $year = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);
    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $capital = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $type = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'DIA', 'origin_module' => 'contable']);
    app(PostJournalService::class)->post($company, $type, new DateTime('2026-03-05'), new DateTime('2026-03-05'), [
        new JournalLineInput($cash->id, $company->local_currency_id, debit: 5000, credit: 0),
        new JournalLineInput($capital->id, $company->local_currency_id, debit: 0, credit: 5000),
    ], 'Aporte');

    test()->actingAs($superuser);

    return compact('superuser', 'license', 'company');
}

function httpBackupExport(array $f, array $override = [])
{
    return test()->postJson(route('backups.export'), [
        'company_id' => $f['company']->id,
        'sections' => ['accounting'],
        'period' => 'all',
        'password' => 'clave-del-archivo',
        'password_confirmation' => 'clave-del-archivo',
        'account_password' => 'password',
        ...$override,
    ]);
}

it('solo el Superusuario: un Administrador no entra a Respaldos', function () {
    $f = httpBackupFixture();

    $this->get(route('backups.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Backups/Index')->where('licenseCompanies.0.name', 'Panadería La Espiga')->has('sections', 7));

    $admin = app(PermissionGrantService::class)->createUser($f['superuser'], $f['company'], ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'], 'admin', []);
    $this->actingAs($admin);

    $this->get(route('backups.index'))->assertForbidden();
    $this->postJson(route('backups.preview'), ['company_id' => $f['company']->id, 'period' => 'all'])->assertForbidden();
    httpBackupExport($f)->assertForbidden();
});

it('antes de exportar, el resumen de lo que va', function () {
    $f = httpBackupFixture();

    $summary = $this->postJson(route('backups.preview'), ['company_id' => $f['company']->id, 'sections' => ['billing'], 'period' => 'range', 'from' => '2026-03-01'])
        ->assertOk()
        ->json();

    expect($summary['company'])->toBe('Panadería La Espiga')
        ->and($summary['period'])->toBe('Desde el 01/03/2026 hasta hoy')
        ->and(array_column($summary['sections'], 'key'))->toBe(['accounting', 'inventory', 'billing'])
        ->and(collect($summary['sections'][0]['items'])->firstWhere('label', 'Asientos')['count'])->toBe(1);

    // Una compañía de otra licencia, no.
    $other = Company::factory()->create();
    $this->postJson(route('backups.preview'), ['company_id' => $other->id, 'period' => 'all'])->assertStatus(422)->assertJsonValidationErrors('company_id');
});

it('exportar pide la contraseña de la cuenta y una del archivo; baja el archivo cifrado y queda en la Bitácora', function () {
    $f = httpBackupFixture();

    httpBackupExport($f, ['account_password' => 'otra'])->assertStatus(422)->assertJsonValidationErrors(['account_password' => 'La contraseña de tu cuenta no es correcta.']);
    httpBackupExport($f, ['password' => 'corta', 'password_confirmation' => 'corta'])->assertStatus(422)->assertJsonValidationErrors('password');
    httpBackupExport($f, ['password_confirmation' => 'distinta-de-la-otra'])->assertStatus(422)->assertJsonValidationErrors('password');

    $response = httpBackupExport($f)->assertOk();
    $exported = fn () => AuditLog::where('company_id', $f['company']->id)->where('action', 'company.backup_exported')->exists();

    // Se anota cuando el archivo salió entero, no antes de empezar a bajar.
    expect($exported())->toBeFalse();
    $content = $response->streamedContent();
    expect($exported())->toBeTrue();

    expect($response->headers->get('Content-Disposition'))->toContain('respaldo-panaderia-la-espiga-inicio-al-')
        ->and($content)->toStartWith("CONTAPP RESPALDO CIFRADO\nversion: 1\ncompania: Panadería La Espiga (3-101-555555)")
        // Nada legible adentro.
        ->and($content)->not->toContain('Aporte')
        ->and(AuditLog::where('company_id', $f['company']->id)->where('action', 'company.backup_exported')->value('user_id'))->toBe($f['superuser']->id);

    Notification::assertNothingSent();
});

it('importar: se revisa, se confirma y crea una compañía nueva con lo que trae', function () {
    $f = httpBackupFixture();
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());

    $this->post(route('backups.import'), ['file' => $file, 'password' => 'otra-clave'], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'La contraseña no es la de este respaldo, o el archivo está dañado.']);

    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());
    $upload = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->assertOk()->json();

    expect($upload['summary']['company']['legal_name'])->toBe('Panadería La Espiga S.A.')
        ->and($upload['summary']['exported']['correo'])->toBe('dueno@example.com')
        ->and($upload['defaults']['tax_id'])->toBe('3-101-555555')
        // La original sigue en la licencia: se propone «(copia)» para no confundirlas.
        ->and($upload['defaults']['legal_name'])->toBe('Panadería La Espiga S.A. (copia)')
        ->and($upload['defaults']['trade_name'])->toBe('Panadería La Espiga (copia)');

    $this->postJson(route('backups.import.confirm', $upload['uuid']), ['legal_name' => 'La Espiga (copia)', 'account_password' => 'otra'])
        ->assertStatus(422)->assertJsonValidationErrors('account_password');

    // En las pruebas la cola corre en el momento.
    $status = $this->postJson(route('backups.import.confirm', $upload['uuid']), ['legal_name' => 'La Espiga (copia)', 'account_password' => 'password'])->assertOk()->json();
    expect($this->getJson(route('backups.import.status', $upload['uuid']))->json('status'))->toBe('done');

    $backup = CompanyBackup::where('uuid', $upload['uuid'])->sole();
    $copy = Company::findOrFail($backup->company_id);

    expect($copy->legal_name)->toBe('La Espiga (copia)')
        ->and($copy->license_id)->toBe($f['license']->id)
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $copy->id)->count())->toBe(1)
        ->and($backup->result['imported'])->toBeGreaterThan(0)
        // Nada queda guardado: ni el archivo ni su contraseña.
        ->and($backup->file_path)->toBeNull()
        ->and($backup->getRawOriginal('secret'))->toBeNull()
        ->and(Storage::disk('local')->allFiles('backups'))->toBe([])
        ->and(AuditLog::where('company_id', $copy->id)->where('action', 'company.backup_imported')->exists())->toBeTrue();

    // Ya importado, no se confirma otra vez.
    $this->postJson(route('backups.import.confirm', $upload['uuid']), ['legal_name' => 'Otra', 'account_password' => 'password'])->assertStatus(422);

    // En la lista, cuántos registros quedaron fuera.
    $this->get(route('backups.index'))->assertInertia(fn ($page) => $page->where('imports.0.status', 'done')->where('imports.0.dropped', 0));
});

it('sin cupo en la licencia no se importa', function () {
    $f = httpBackupFixture();
    $f['license']->update(['max_companies' => 1]);
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());

    $upload = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->assertOk()->json();
    expect($upload['can_add_company'])->toBeFalse();

    $this->postJson(route('backups.import.confirm', $upload['uuid']), ['legal_name' => 'Copia', 'account_password' => 'password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('legal_name');

    expect(Company::where('license_id', $f['license']->id)->count())->toBe(1);
});

it('el backoffice exporta cualquier compañía, y al Superusuario le llega un correo', function () {
    $f = httpBackupFixture();
    $propietario = loginAsPropietario(['name' => 'Soporte Pivox']);

    $this->get(route('backoffice.licenses.backups.index', $f['license']->id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Backoffice/Licenses/Backups')->where('superuser.email', 'dueno@example.com'));

    $this->postJson(route('backoffice.licenses.backups.export', $f['license']->id), [
        'company_id' => $f['company']->id, 'sections' => [], 'period' => 'all',
        'password' => 'clave-del-archivo', 'password_confirmation' => 'clave-del-archivo', 'account_password' => 'password',
    ])->assertOk()->streamedContent();

    Notification::assertSentTo($f['superuser'], CompanyBackupExportedNotification::class);

    // En la Bitácora de la compañía, a nombre del equipo de Pivox; y en el historial de la licencia.
    expect(AuditLog::where('company_id', $f['company']->id)->where('action', 'company.backup_exported')->value('new_values')['by'])->toBe('Equipo de Pivox (Soporte Pivox)')
        ->and(AuditLog::where('propietario_id', $propietario->id)->where('action', 'license_company_backup_exported')->exists())->toBeTrue();
});

it('subir solo comprueba la contraseña: la revisión completa va a la cola, y la pantalla la sigue', function () {
    $f = httpBackupFixture();
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());
    Queue::fake();

    $upload = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->assertOk()->json();

    expect($upload['status'])->toBe('inspecting')
        ->and($upload)->not->toHaveKey('summary');
    Queue::assertPushedOn('backups', InspectCompanyBackupJob::class);

    // Lo que hace la cola.
    $backup = CompanyBackup::where('uuid', $upload['uuid'])->sole();
    (new InspectCompanyBackupJob($backup->id))->handle(app(BackupImporter::class), app(CompanyBackupService::class));

    $status = $this->getJson(route('backups.import.status', $upload['uuid']))->assertOk()->json();
    expect($status['status'])->toBe('validated')
        ->and($status['summary']['company']['legal_name'])->toBe('Panadería La Espiga S.A.')
        ->and($status['total'])->toBeGreaterThan(0);
});

it('completar una compañía: se compara, se confirma y le agrega solo lo que le falta', function () {
    $f = httpBackupFixture();
    $center = CostCenter::factory()->create(['company_id' => $f['company']->id, 'code' => 'CC-01']);
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());

    // Se perdió el centro de costo después de exportar.
    DB::table('cost_centers')->where('id', $center->id)->delete();

    $uuid = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->json('uuid');

    // Con una compañía de otra licencia, no.
    $this->postJson(route('backups.import.compare', $uuid), ['company_id' => Company::factory()->create()->id])->assertStatus(422)->assertJsonValidationErrors('company_id');

    // En las pruebas la cola corre en el momento.
    $compared = $this->postJson(route('backups.import.compare', $uuid), ['company_id' => $f['company']->id])->assertOk()->json();

    expect($compared['status'])->toBe('compared')
        ->and($compared['comparison']['conflicts'])->toBe([])
        ->and($compared['comparison']['totals']['added'])->toBe(1)
        ->and(collect($compared['comparison']['sections'][0]['items'])->firstWhere('label', 'Asientos'))->toMatchArray(['added' => 0, 'present' => 1])
        // Comparar no guarda nada.
        ->and(DB::table('cost_centers')->where('company_id', $f['company']->id)->exists())->toBeFalse();

    $this->postJson(route('backups.import.confirm', $uuid), ['mode' => 'merge', 'account_password' => 'otra'])->assertStatus(422)->assertJsonValidationErrors('account_password');
    $done = $this->postJson(route('backups.import.confirm', $uuid), ['mode' => 'merge', 'account_password' => 'password'])->assertOk()->json();

    expect($done['status'])->toBe('done')
        ->and($done['company_id'])->toBe($f['company']->id)
        ->and($done['comparison']['totals']['added'])->toBe(1)
        ->and(DB::table('cost_centers')->where('company_id', $f['company']->id)->value('code'))->toBe('CC-01')
        // Ninguna compañía nueva, y el asiento sigue siendo uno.
        ->and(Company::where('license_id', $f['license']->id)->count())->toBe(1)
        ->and(JournalEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $f['company']->id)->count())->toBe(1)
        ->and(AuditLog::where('company_id', $f['company']->id)->where('action', 'company.backup_merged')->value('new_values')['added'])->toBe(1)
        ->and(Storage::disk('local')->allFiles('backups'))->toBe([]);
});

it('si la comparación encontró algo que lo impide, no se confirma', function () {
    $f = httpBackupFixture();
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());
    $uuid = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->json('uuid');
    $this->postJson(route('backups.import.compare', $uuid), ['company_id' => $f['company']->id])->assertOk();

    // Como si la comparación hubiera encontrado un documento distinto.
    $backup = CompanyBackup::where('uuid', $uuid)->sole();
    $backup->update(['comparison' => ['conflicts' => ['El asiento DIA-1 ya está en esta compañía con otros datos.'], 'conflicts_total' => 1] + $backup->comparison]);

    $this->postJson(route('backups.import.confirm', $uuid), ['mode' => 'merge', 'account_password' => 'password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('company_id');

    expect($backup->fresh()->status)->toBe('compared');
});

it('uno que espera confirmación se retoma desde la lista, o se descarta y no queda guardado', function () {
    $f = httpBackupFixture();
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());
    $uuid = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->json('uuid');

    // Al volver a la pantalla, la fila se puede retomar: lo que trae sigue ahí.
    $this->get(route('backups.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('imports.0.uuid', $uuid)->where('imports.0.status', 'validated')->where('imports.0.resumable', true));
    expect($this->getJson(route('backups.import.status', $uuid))->json('summary.company.legal_name'))->toBe('Panadería La Espiga S.A.');

    // Otra licencia no lo ve ni lo descarta.
    $stranger = User::factory()->create(['is_super_admin' => true]);
    $otherCompany = Company::factory()->create(['license_id' => License::factory()->create(['superuser_id' => $stranger->id])->id]);
    $otherCompany->users()->attach($stranger->id, ['is_default' => true]);
    $stranger->update(['default_company_id' => $otherCompany->id]);
    $this->actingAs($stranger)->deleteJson(route('backups.import.cancel', $uuid))->assertNotFound();
    $this->actingAs($f['superuser']);

    $cancelled = $this->deleteJson(route('backups.import.cancel', $uuid))->assertOk()->json();
    $backup = CompanyBackup::where('uuid', $uuid)->sole();

    expect($cancelled['status'])->toBe('cancelled')
        ->and($backup->finished_at)->not->toBeNull()
        ->and($backup->getRawOriginal('secret'))->toBeNull()
        ->and(Storage::disk('local')->allFiles('backups'))->toBe([]);

    // Ya descartado: no se retoma, no se confirma ni se descarta otra vez.
    $this->get(route('backups.index'))->assertInertia(fn ($page) => $page->where('imports.0.resumable', false));
    $this->postJson(route('backups.import.confirm', $uuid), ['legal_name' => 'Copia', 'account_password' => 'password'])->assertStatus(422);
    $this->deleteJson(route('backups.import.cancel', $uuid))->assertStatus(422)->assertJsonValidationErrors('backup');
});

it('uno comparado también se descarta, y desde el backoffice', function () {
    $f = httpBackupFixture();
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());
    $uuid = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->json('uuid');
    $this->postJson(route('backups.import.compare', $uuid), ['company_id' => $f['company']->id])->assertOk();

    loginAsPropietario();
    $this->get(route('backoffice.licenses.backups.index', $f['license']->id))
        ->assertInertia(fn ($page) => $page->where('imports.0.status', 'compared')->where('imports.0.resumable', true));

    $this->deleteJson(route('backoffice.licenses.backups.import.cancel', [$f['license']->id, $uuid]))->assertOk()->assertJsonPath('status', 'cancelled');
    expect(Storage::disk('local')->allFiles('backups'))->toBe([]);
});

it('uno vencido ya no se retoma, pero todavía se puede descartar', function () {
    $f = httpBackupFixture();
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());
    $uuid = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->json('uuid');

    $this->travel(25)->hours();

    $this->get(route('backups.index'))->assertInertia(fn ($page) => $page->where('imports.0.resumable', false));
    $this->deleteJson(route('backups.import.cancel', $uuid))->assertOk()->assertJsonPath('status', 'cancelled');
});

it('un respaldo subido que nadie confirma se borra a las 24 horas', function () {
    $f = httpBackupFixture();
    $file = UploadedFile::fake()->createWithContent('respaldo.txt', httpBackupExport($f)->streamedContent());
    $uuid = $this->post(route('backups.import'), ['file' => $file, 'password' => 'clave-del-archivo'], ['Accept' => 'application/json'])->json('uuid');

    expect(Storage::disk('local')->allFiles('backups'))->toHaveCount(1);

    $this->travel(25)->hours();
    $this->artisan('pivox:prune-backups')->assertSuccessful();

    $backup = CompanyBackup::where('uuid', $uuid)->sole();
    expect($backup->status)->toBe('expired')
        ->and($backup->getRawOriginal('secret'))->toBeNull()
        ->and(Storage::disk('local')->allFiles('backups'))->toBe([]);
});
