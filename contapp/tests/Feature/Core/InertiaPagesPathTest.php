<?php

/*
|--------------------------------------------------------------------------
| Las páginas de Inertia, con las mayúsculas de verdad (config/inertia.php)
|--------------------------------------------------------------------------
|
| En Windows «js/pages» encuentra «js/Pages»; en Linux no, y todas las
| pruebas con assertInertia fallaban en GitHub Actions. Esto lo revisa con
| el nombre exacto, también en Windows.
|
*/

it('busca las páginas en una carpeta que existe con ese nombre exacto', function () {
    foreach (config('inertia.pages.paths') as $path) {
        expect(scandir(dirname($path)))->toContain(basename($path));
    }

    expect(config('inertia.testing.ensure_pages_exist'))->toBeTrue();
});
