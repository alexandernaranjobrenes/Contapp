<?php

use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseCategory;

it('un propietario puede crear, editar y eliminar una categoría de licencia', function () {
    loginAsPropietario();

    $this->post(route('backoffice.license-categories.store'), [
        'name' => 'Básica',
        'max_companies' => 1,
        'max_admins' => 3,
        'max_users' => 10,
        'duration_months' => 12,
        'description' => 'Plan de entrada',
    ])->assertSessionHasNoErrors();

    $category = LicenseCategory::sole();
    expect($category->name)->toBe('Básica')
        ->and($category->max_companies)->toBe(1);

    $this->put(route('backoffice.license-categories.update', $category->id), [
        'name' => 'Básica',
        'max_companies' => 2,
        'max_admins' => 3,
        'max_users' => 10,
        'duration_months' => 12,
        'description' => 'Plan de entrada actualizado',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($category->fresh()->max_companies)->toBe(2);

    $this->delete(route('backoffice.license-categories.destroy', $category->id))->assertSessionHasNoErrors();
    expect(LicenseCategory::find($category->id))->toBeNull();
});

it('rechaza eliminar una categoría que ya tiene licencias emitidas', function () {
    loginAsPropietario();

    $category = LicenseCategory::factory()->create();
    License::factory()->create(['category_id' => $category->id]);

    $this->delete(route('backoffice.license-categories.destroy', $category->id))->assertSessionHasErrors('category');
    expect(LicenseCategory::find($category->id))->not->toBeNull();
});

it('rechaza el acceso al catálogo de categorías a un usuario de compañía: no es Propietario', function () {
    logInAsCompanyUser();

    $this->get(route('backoffice.license-categories.index'))->assertRedirect(route('backoffice.login'));
});
