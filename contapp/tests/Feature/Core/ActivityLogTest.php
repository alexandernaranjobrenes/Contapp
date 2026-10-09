<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Services\ActivityRecorder;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\AuditCatalog;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\ScreenCatalog;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| La Bitácora de la compañía (Administración → Bitácora)
|--------------------------------------------------------------------------
|
| ActivityRecorder anota todo lo que cambia —una fila por pedido, con lo de
| antes y lo de después— y ActivityLogService lo muestra: de a 15, al
| Superusuario todo y a un Administrador lo de sus pantallas.
|
*/

beforeEach(fn () => Notification::fake());

/** Lo que dejaron las factories al preparar la prueba no es parte de lo que se prueba. */
function freshActivity(): void
{
    app(ActivityRecorder::class)->flush();
    AuditLog::query()->delete();
}

function activityAdmin(User $superAdmin, $company, array $levels): User
{
    $admin = app(PermissionGrantService::class)->createUser(
        $superAdmin, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin', [],
    );
    app(PermissionGrantService::class)->writeScreenLevels($company->id, $admin, $levels);

    return $admin;
}

it('cada modelo está en la Bitácora o tiene su motivo para no estar, con pantallas que existen', function () {
    $classes = collect(glob(app_path('Domains/*/Models/*.php')))
        ->map(fn (string $file) => 'App\\Domains\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php'))
        ->push(User::class)
        ->filter(fn (string $class) => is_subclass_of($class, Model::class));

    foreach ($classes as $class) {
        expect(isset(AuditCatalog::MODELS[$class]) || isset(AuditCatalog::IGNORED[$class]))
            ->toBeTrue("{$class}: agregalo a AuditCatalog::MODELS (o a IGNORED con el motivo)");
    }

    foreach (AuditCatalog::MODELS as $class => [, , $gender, , $screen]) {
        expect($gender)->toBeIn(['m', 'f'])
            ->and($screen === null || ScreenCatalog::exists($screen))->toBeTrue("{$class}: la pantalla {$screen} no existe");
    }
});

it('crear un artículo con sus cuentas es UNA fila: el artículo, con sus cuentas adentro', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01', 'description_es' => 'Inventario']);
    freshActivity();

    $this->post(route('items.store'), [
        'code' => 'ART-1', 'name' => 'Laptop', 'uom_id' => $uom->id, 'status' => 'active',
        'is_inventory_item' => true, 'accounts' => ['inventory' => $account->id],
    ])->assertSessionHasNoErrors();

    $row = AuditLog::where('action', AuditLog::ACTIVITY)->sole();
    expect($row->company_id)->toBe($company->id)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->route)->toBe('items.store')
        ->and($row->screen)->toBe('inventory.items')
        ->and($row->auditable_type)->toBe(Item::class)
        ->and($row->subject)->toBe('ART-1 — Laptop')
        ->and(collect($row->changes['records'])->pluck('t')->unique()->values()->all())->toContain(Item::class);

    $this->get(route('activity-log.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ActivityLog/Index')
            ->where('log.entries.0.sentence', 'creó el artículo ART-1 — Laptop')
            ->where('log.entries.0.actor.name', $user->name)
            ->where('log.entries.0.section', 'Inventario · Artículos')
            ->where('log.entries.0.groups.0.title', 'Artículo ART-1 — Laptop')
            ->where('log.entries.0.groups.0.badge', 'Alta')
            ->where('log.entries.0.groups.1.title', 'Determinaciones de cuenta'));
});

it('al editar guarda lo de antes y lo de después, y lo que no cambió no se anota', function () {
    ['company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);
    $item = Item::factory()->create(['company_id' => $company->id, 'code' => 'ART-1', 'name' => 'Laptop', 'uom_id' => $uom->id]);
    freshActivity();

    $data = ['name' => 'Laptop 14"', 'uom_id' => $uom->id, 'status' => 'active', 'is_inventory_item' => true];
    $this->put(route('items.update', $item->id), $data)->assertSessionHasNoErrors();

    $record = AuditLog::where('action', AuditLog::ACTIVITY)->sole()->changes['records'][0];
    expect($record['a'])->toBe('updated')
        ->and($record['f']['name'])->toBe(['Laptop', 'Laptop 14"']);

    // Guardar lo mismo otra vez no es un movimiento.
    $this->put(route('items.update', $item->id), $data)->assertSessionHasNoErrors();
    expect(AuditLog::where('action', AuditLog::ACTIVITY)->count())->toBe(1);
});

it('no guarda claves y enmascara las cuentas bancarias', function () {
    $account = new BankAccount(['bank_name' => 'BAC', 'account_number' => 'CR05015202001026284066', 'password' => 'x']);

    $fields = AuditCatalog::fields($account, 'created');

    expect($fields['account_number'][1])->toBe('••••4066')
        ->and($fields)->not->toHaveKey('password')
        ->and(AuditCatalog::subject($account))->toBe('BAC ••••4066');
});

it('lo que se deshizo en una transacción no queda', function () {
    ['company' => $company] = logInAsCompanyUser();
    app(CurrentCompany::class)->set($company->id);
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);
    freshActivity();

    try {
        DB::transaction(function () use ($company, $uom) {
            Item::factory()->create(['company_id' => $company->id, 'uom_id' => $uom->id]);
            throw new RuntimeException('falló a mitad');
        });
    } catch (RuntimeException) {
    }

    app(ActivityRecorder::class)->flush();
    expect(AuditLog::count())->toBe(0);
});

it('lo de un proceso automático queda a nombre de «Sistema»', function () {
    ['company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $uom = UnitOfMeasure::factory()->create(['company_id' => $company->id]);
    freshActivity();
    // Sin nadie con sesión, como el comando de cada día (y sin anotar un cierre de sesión).
    Auth::guard('web')->forgetUser();

    app(ActivityRecorder::class)->asSystem(fn () => Item::factory()->create(['company_id' => $company->id, 'uom_id' => $uom->id]));

    $row = AuditLog::sole();
    expect($row->user_id)->toBeNull()->and($row->ip_address)->toBeNull()->and($row->screen)->toBe('inventory.items');
});

it('anota los inicios y cierres de sesión en cada compañía de la persona', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser();
    Auth::guard('web')->forgetUser();
    freshActivity();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->delete(route('logout'))->assertRedirect();

    expect(AuditLog::where('company_id', $company->id)->pluck('action')->all())->toBe(['auth.login', 'auth.logout']);
});

it('de a 15, y «Ver más» trae las siguientes', function () {
    ['user' => $user, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    freshActivity();
    $login = ['company_id' => $company->id, 'user_id' => $user->id, 'action' => 'auth.login', 'auditable_type' => User::class, 'auditable_id' => $user->id];
    AuditLog::insert(array_fill(0, 20, $login + ['created_at' => now()]));

    $first = $this->get(route('activity-log.index'))->assertOk()->inertiaProps('log');
    expect($first['entries'])->toHaveCount(15)->and($first['has_more'])->toBeTrue();

    $more = $this->getJson(route('activity-log.more', ['before' => collect($first['entries'])->last()['id']]))->assertOk()->json();
    expect($more['entries'])->toHaveCount(5)
        ->and($more['has_more'])->toBeFalse()
        ->and($more['entries'][0]['sentence'])->toBe('inició sesión');
});

it('el Superusuario ve todo; un Administrador, lo de sus pantallas; un Usuario, nada', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $row = fn (string $screen, string $subject) => AuditLog::create([
        'company_id' => $company->id, 'user_id' => $superAdmin->id, 'action' => AuditLog::ACTIVITY, 'route' => null,
        'screen' => $screen, 'auditable_type' => Item::class, 'auditable_id' => 1, 'subject' => $subject,
        'changes' => ['records' => [['t' => Item::class, 'id' => 1, 'a' => 'created', 's' => $subject, 'f' => []]], 'omitted' => []],
        'created_at' => now(),
    ]);
    $row('inventory.items', 'ART-1');
    $row('payroll.employees', 'SALARIO');
    AuditLog::create(['company_id' => $company->id, 'user_id' => $superAdmin->id, 'action' => 'conti_access_updated', 'auditable_type' => User::class, 'auditable_id' => $superAdmin->id, 'created_at' => now()]);

    $all = collect($this->get(route('activity-log.index'))->inertiaProps('log.entries'))->pluck('sentence');
    expect($all)->toHaveCount(3);

    $admin = activityAdmin($superAdmin, $company, ['inventory.items' => 'read']);
    $seen = collect($this->actingAs($admin)->get(route('activity-log.index'))->assertOk()->inertiaProps('log.entries'))->pluck('sentence');
    expect($seen->implode('|'))->toContain('ART-1')
        ->not->toContain('SALARIO')
        ->not->toContain('Conti');

    ['user' => $plain] = logInAsCompanyUser($company);
    $this->actingAs($plain)->get(route('activity-log.index'))->assertForbidden();
});
