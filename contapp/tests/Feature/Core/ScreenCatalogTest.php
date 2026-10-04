<?php

use App\Domains\Core\Support\ScreenCatalog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| El catálogo de pantallas del menú, al día con las rutas y con el menú
|--------------------------------------------------------------------------
|
| Los permisos son por pantalla (ScreenCatalog). Si una ruta nueva no
| pertenece a ninguna, la cuidaría solo el permiso de su módulo, y alguien
| con cualquier pantalla de ese módulo entraría. Estos tests lo impiden.
|
*/

/** @return array<string, array{module: ?string, level: ?string, screenAccess: ?string, methods: list<string>}> */
function appRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || str_starts_with($name, 'backoffice.')) {
            continue;
        }

        $module = $level = $screenAccess = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'module-access:')) {
                [$module, $level] = explode(',', Str::after($middleware, 'module-access:'));
            }
            if (is_string($middleware) && str_starts_with($middleware, 'screen-access:')) {
                $screenAccess = Str::after($middleware, 'screen-access:');
            }
        }

        $routes[$name] = ['module' => $module, 'level' => $level, 'screenAccess' => $screenAccess, 'methods' => $route->methods()];
    }

    return $routes;
}

it('cada ruta protegida por módulo pertenece a una pantalla, o está entre las compartidas', function () {
    $orphans = collect(appRoutes())
        ->filter(fn (array $route) => $route['module'] !== null)
        ->keys()
        ->filter(fn (string $name) => ScreenCatalog::screenForRoute($name) === null && ! ScreenCatalog::isShared($name))
        ->values()
        ->all();

    expect($orphans)->toBe([]);
});

it('cada pantalla y cada ruta compartida calzan con rutas que existen', function () {
    $names = array_keys(appRoutes());

    foreach (ScreenCatalog::screens() as $key => $screen) {
        foreach ($screen['routes'] as $pattern) {
            expect(collect($names)->contains(fn ($name) => Str::is($pattern, $name)))->toBeTrue("«{$pattern}» de {$key} no calza con ninguna ruta");
        }
    }

    foreach (ScreenCatalog::SHARED_ROUTES as $pattern) {
        expect(collect($names)->contains(fn ($name) => Str::is($pattern, $name)))->toBeTrue("«{$pattern}» compartida no calza con ninguna ruta");
    }
});

it('las rutas de una pantalla están en el grupo de su módulo, y las de solo consulta no escriben', function () {
    foreach (appRoutes() as $name => $route) {
        $key = ScreenCatalog::screenForRoute($name);

        if ($key === null) {
            continue;
        }

        $screen = ScreenCatalog::find($key);

        if ($route['module'] !== null) {
            expect($route['module'])->toBe($screen['module'], "{$name} es de «{$key}», pero su grupo es del módulo {$route['module']}");
        }

        if ($screen['read_only']) {
            expect($route['level'])->toBe('read', "{$name} escribe, y «{$key}» es de solo consulta");
        }
    }
});

it('una pantalla que no es de solo consulta tiene algo que escribir', function () {
    $routes = appRoutes();

    foreach (ScreenCatalog::screens() as $key => $screen) {
        if ($screen['read_only']) {
            continue;
        }

        $writes = collect($routes)->filter(fn (array $route, string $name) => ScreenCatalog::screenForRoute($name) === $key
            && ($route['level'] === 'read_write' || str_ends_with((string) $route['screenAccess'], ',read_write')));

        expect($writes)->not->toBeEmpty("«{$key}» no tiene ninguna ruta que escriba: debería ser de solo consulta");
    }
});

it('una ruta de una pantalla fuera de los grupos por módulo pide su pantalla (salvo la consulta abierta del catálogo de impuestos)', function () {
    $open = ['tax-rates.index'];

    foreach (appRoutes() as $name => $route) {
        if (ScreenCatalog::screenForRoute($name) === null || $route['module'] !== null || in_array($name, $open, true)) {
            continue;
        }

        expect($route['screenAccess'])->not->toBeNull("{$name} es de una pantalla y nada la protege");
    }
});

it('las pantallas del catálogo son las mismas que las del menú lateral', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
    preg_match_all("/screen: '([a-z_.]+)'/", $layout, $matches);

    $menu = collect($matches[1])->sort()->values()->all();
    $catalog = collect(ScreenCatalog::keys())->sort()->values()->all();

    expect($menu)->toBe($catalog);
});

it('resuelve primero el nombre exacto y después el comodín', function () {
    expect(ScreenCatalog::screenForRoute('sales-documents.create'))->toBe('billing.new_invoice')
        ->and(ScreenCatalog::screenForRoute('sales-documents.index'))->toBe('billing.sales_documents')
        ->and(ScreenCatalog::screenForRoute('fx-revaluation.store'))->toBe('accounting.fx_revaluation_run')
        ->and(ScreenCatalog::screenForRoute('fx-revaluation.index'))->toBe('accounting.fx_revaluation_history')
        ->and(ScreenCatalog::screenForRoute('item-lots.options'))->toBeNull()
        ->and(ScreenCatalog::screenForRoute('item-lots.index'))->toBe('inventory.items')
        ->and(ScreenCatalog::screenForRoute('ledger.show'))->toBeNull()
        ->and(ScreenCatalog::screenForRoute('dashboard'))->toBeNull();
});
