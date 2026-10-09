<?php

use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\PermissionProfiles;
use App\Domains\Core\Support\ScreenCatalog;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Perfiles de permisos: precargan el editor al invitar y al editar
|--------------------------------------------------------------------------
|
| config/permission_profiles.php. No se guardan: lo que se guarda son los
| permisos que quedan en el editor, como siempre.
|
*/

beforeEach(fn () => Notification::fake());

function profile(string $roleType, string $key): array
{
    return collect(PermissionProfiles::for($roleType))->firstWhere('key', $key);
}

it('cada perfil nombra secciones y pantallas que existen, con niveles válidos', function () {
    $sections = array_column(ScreenCatalog::sections(), 'key');

    foreach (['admin', 'user'] as $roleType) {
        expect(config("permission_profiles.{$roleType}"))->not->toBeEmpty();

        foreach (config("permission_profiles.{$roleType}") as $key => $profile) {
            expect($profile['label'] ?? null)->toBeString("{$roleType}.{$key}: sin nombre")
                ->and($profile['description'] ?? null)->toBeString("{$roleType}.{$key}: sin descripción");

            foreach ($profile['sections'] ?? [] as $section => $level) {
                expect($section === '*' || in_array($section, $sections, true))->toBeTrue("{$roleType}.{$key}: la sección «{$section}» no existe")
                    ->and($level)->toBeIn(['none', 'read', 'read_write']);
            }

            foreach ($profile['screens'] ?? [] as $screen => $level) {
                expect(ScreenCatalog::exists($screen))->toBeTrue("{$roleType}.{$key}: la pantalla «{$screen}» no existe")
                    ->and($level)->toBeIn(['none', 'read', 'read_write']);
            }
        }
    }
});

it('un perfil da un nivel por pantalla: la pantalla le gana a la sección, y un reporte llega hasta Lectura', function () {
    $seller = profile('user', 'vendedor')['levels'];

    expect(array_keys($seller))->toBe(ScreenCatalog::keys())
        ->and($seller['billing.new_invoice'])->toBe('read_write')
        ->and($seller['billing.settings'])->toBe('read_write')
        ->and($seller['billing.price_overrides'])->toBe('read')
        ->and($seller['inventory.items'])->toBe('read')
        ->and($seller['business_partners.partners'])->toBe('read')
        ->and($seller['accounting.journal_entries'])->toBe('none')
        ->and($seller['payroll.employees'])->toBe('none');

    $assistant = profile('user', 'asistente_contable')['levels'];
    expect($assistant['accounting.journal_entries'])->toBe('read_write')
        ->and($assistant['accounting.period_close'])->toBe('read')
        ->and($assistant['accounting.chart_of_accounts'])->toBe('read')
        ->and($assistant['banking.reconciliations'])->toBe('none');

    $general = profile('admin', 'administrador_general')['levels'];
    expect($general['payroll.employees'])->toBe('read_write')
        ->and($general['reports.trial_balance'])->toBe('read');
});

it('al invitar llegan los perfiles de los roles que se pueden dar, y no se guarda ninguno', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);

    $this->get(route('users.create'))
        ->assertInertia(fn ($page) => $page->component('Users/Create')
            ->where('profiles.admin.0.key', 'administrador_general')
            ->where('profiles.user.2.key', 'vendedor')
            ->where('profiles.user.2.levels', fn ($levels) => $levels['billing.new_invoice'] === 'read_write'));

    // Un Administrador solo invita Usuarios: solo esos perfiles.
    $admin = app(PermissionGrantService::class)->createUser(
        $superAdmin, $company,
        ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
        'admin', [Module::where('code', 'accounting')->firstOrFail()->id => 'read'],
    );
    $this->actingAs($admin)->get(route('users.create'))
        ->assertInertia(fn ($page) => $page->missing('profiles.admin')->has('profiles.user'));

    // Lo que se guarda son los permisos; el perfil no viaja.
    $this->actingAs($superAdmin)->post(route('users.store'), [
        'email' => 'beto@example.com',
        'role_type' => 'user',
        'permissions' => profile('user', 'vendedor')['levels'],
        'profile' => 'vendedor',
    ])->assertRedirect(route('users.index'));

    $invitation = CompanyInvitation::sole();
    expect($invitation->screen_permissions['billing.new_invoice'])->toBe('read_write')
        ->and($invitation->getAttributes())->not->toHaveKey('profile');
});

it('al editar llegan los perfiles del rol de la persona', function () {
    ['user' => $superAdmin, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);

    $user = app(PermissionGrantService::class)->createUser(
        $superAdmin, $company,
        ['name' => 'Beto', 'email' => 'beto@example.com', 'password' => 'Secreto#1234'],
        'user', [],
    );

    $this->get(route('users.permissions.edit', $user->id))
        ->assertInertia(fn ($page) => $page->component('Users/Permissions')
            ->where('targetUser.role_type', 'user')
            ->where('profiles.0.key', 'contador')
            ->has('profiles', count(config('permission_profiles.user'))));
});
