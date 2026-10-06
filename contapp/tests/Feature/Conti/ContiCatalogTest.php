<?php

use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Reports\ContiReportCatalog;
use App\Domains\Conti\Resources\ContiResourceCatalog;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Conti\Support\ContiRedactor;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\ScreenCatalog;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| El catálogo de Conti, en orden
|--------------------------------------------------------------------------
|
| Lo que Conti consulta, los reportes que pide y lo que guarda piden
| pantallas que existen en el menú, y ningún conjunto devuelve un campo
| sensible.
|
*/

it('cada consulta y cada reporte piden pantallas que existen en el menú', function () {
    foreach (ContiResourceCatalog::all() as $resource) {
        foreach ($resource->screens as $screen) {
            expect(ScreenCatalog::exists($screen))->toBeTrue("La consulta «{$resource->key}» pide la pantalla «{$screen}», que no existe.");
        }
    }

    foreach (ContiReportCatalog::all() as $report) {
        expect($report->screens)->not->toBeEmpty();
        foreach ($report->screens as $screen) {
            expect(ScreenCatalog::exists($screen))->toBeTrue("El reporte «{$report->key}» pide la pantalla «{$screen}», que no existe.");
        }
    }
});

it('cada acción pide Lectura y escritura en una pantalla que admite escribir', function () {
    foreach (ContiActionCatalog::all() as $action) {
        expect(ScreenCatalog::exists($action->screen()))->toBeTrue("La acción «{$action->key()}» pide una pantalla que no existe.")
            ->and(ScreenCatalog::maxLevel($action->screen()))->toBe('read_write');
    }
});

it('cada consulta arma sus registros con campos en limpio, aun sin datos', function () {
    ['user' => $user, 'company' => $company] = contiUser([], null, true);
    app(CurrentCompany::class)->set($company->id);
    app(ContiContext::class)->set($user, $company, false);

    foreach (ContiResourceCatalog::all() as $resource) {
        // Un registro vacío: cada relación vale null y cada lista viene vacía.
        $model = $resource->newQuery()->getModel()->newInstance();

        foreach ([$resource->present($model), $resource->presentDetail($model)] as $row) {
            foreach (array_keys($row) as $key) {
                expect(ContiRedactor::isRemovedKey((string) $key))->toBeFalse("La consulta «{$resource->key}» devuelve el campo sensible «{$key}».");
            }
        }
    }

    app(CurrentCompany::class)->clear();
});
