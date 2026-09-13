<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Crea (o usa) una compañía, un usuario perteneciente a ella como default, y
 * autentica al TestCase actual como ese usuario. Común a los tests HTTP que
 * necesitan una sesión con SetCurrentCompany ya resuelto.
 *
 * Otorga de oficio 'read_write' en TODOS los módulos del rollout de
 * enforcement (module_permissions/EnsureModuleAccess, ver docs/decisiones.md
 * — arrancó con "reports", se va extendiendo módulo por módulo): este
 * helper representa "un usuario normal de la compañía para probar cualquier
 * otra cosa", no el propio gate de permisos (eso lo prueban tests dedicados,
 * ver ReportsModuleAccessHttpTest/TaxModuleAccessHttpTest) — sin este
 * grant amplio, CADA módulo nuevo que se conecte a enforcement rompería de
 * golpe todos los tests HTTP existentes de ese módulo. Generalizado a
 * "todos los módulos" (en vez de otorgar uno por uno a medida que se
 * conecta cada módulo) para no tener que volver a tocar este helper en
 * cada entrega futura del rollout.
 */
function logInAsCompanyUser(?\App\Domains\Core\Models\Company $company = null, array $userAttributes = []): array
{
    $company ??= \App\Domains\Core\Models\Company::factory()->create();

    $user = \App\Models\User::factory()->create(array_merge([
        'default_company_id' => $company->id,
    ], $userAttributes));

    $company->users()->attach($user->id, ['is_default' => true]);

    grantAllModuleAccess($user, $company);

    test()->actingAs($user);

    return compact('user', 'company');
}

/**
 * Ver logInAsCompanyUser(): otorga 'read_write' en cada módulo del catálogo
 * (ver Database\Seeders\ModuleSeeder) al usuario, creando los módulos con
 * firstOrCreate si todavía no existen (los tests no dependen de que el
 * seeder haya corrido).
 */
function grantAllModuleAccess(\App\Models\User $user, \App\Domains\Core\Models\Company $company): void
{
    $modules = [
        ['code' => 'accounting', 'name' => 'Contabilidad (asientos, catálogo, cierres)'],
        ['code' => 'business_partners', 'name' => 'Socios de negocio y cartera'],
        ['code' => 'banking', 'name' => 'Bancos y conciliaciones'],
        ['code' => 'tax', 'name' => 'Impuestos (IVA)'],
        ['code' => 'reports', 'name' => 'Reportería'],
    ];

    foreach ($modules as $moduleData) {
        $module = \App\Domains\Core\Models\Module::firstOrCreate(['code' => $moduleData['code']], $moduleData);

        \App\Domains\Core\Models\ModulePermission::firstOrCreate(
            [
                'company_id' => $company->id,
                'module_id' => $module->id,
                'subject_type' => 'user',
                'subject_id' => $user->id,
            ],
            ['access_level' => 'read_write']
        );
    }
}

/**
 * Crea un Propietario (CLAUDE.md secc. 11) y lo autentica en el guard
 * 'propietario' — completamente separado del guard 'web' de
 * logInAsCompanyUser(). Ambos pueden coexistir en el mismo test (dos guards,
 * dos sesiones independientes) cuando hace falta armar un fixture de
 * compañía y además actuar como Propietario en el mismo test.
 */
function loginAsPropietario(array $attributes = []): \App\Domains\Licensing\Models\Propietario
{
    $propietario = \App\Domains\Licensing\Models\Propietario::factory()->create($attributes);

    test()->actingAs($propietario, 'propietario');

    // actingAs() llama internamente a Auth::shouldUse('propietario'), que
    // muta auth.defaults.guard para el resto del proceso de test — un
    // efecto secundario que NUNCA ocurre en producción para una request
    // real fuera de /backoffice/* (ahí solo lo hace, transitoriamente,
    // el propio middleware Authenticate de esa request). Sin este reset,
    // cualquier resolución de guard "default" (auth sin guard explícito,
    // o actingAs($user) sin guard) en el resto del test quedaría
    // apuntando a 'propietario' en vez de 'web', dando falsos positivos.
    config(['auth.defaults.guard' => 'web']);

    return $propietario;
}
