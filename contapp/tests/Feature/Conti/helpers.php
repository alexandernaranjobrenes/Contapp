<?php

use App\Domains\Conti\Services\ContiTokenService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Services\PermissionGrantService;
use App\Models\User;

/*
| Ayudas de las pruebas de Conti (CLAUDE.md secc. 32). Cada archivo de
| pruebas de esta carpeta las incluye.
*/

if (! function_exists('contiUser')) {
    /** Una persona de la compañía con estos niveles por pantalla (o Superusuario). */
    function contiUser(array $levels = [], ?Company $company = null, bool $superuser = false): array
    {
        $company ??= Company::factory()->create();
        $user = User::factory()->create([
            'default_company_id' => $company->id,
            'status' => 'active',
            'is_super_admin' => $superuser,
        ]);
        $company->users()->attach($user->id, ['is_default' => true]);

        foreach (['accounting', 'reports', 'billing', 'tax', 'inventory', 'banking', 'business_partners', 'payroll'] as $code) {
            Module::firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
        }

        if ($levels !== []) {
            app(PermissionGrantService::class)->writeScreenLevels($company->id, $user, $levels);
        }

        return compact('company', 'user');
    }

    /** El pase que CONTAPP le daría al agente para esa persona en esa compañía. */
    function contiToken(User $user, Company $company): string
    {
        return app(ContiTokenService::class)->issue($user, $company->id);
    }

    /** Las cabeceras con las que llega el agente de n8n. */
    function contiHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
