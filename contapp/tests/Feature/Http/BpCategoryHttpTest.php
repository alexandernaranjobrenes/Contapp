<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;

it('solo lista las categorías de la compañía activa del usuario autenticado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $catA = BpCategory::factory()->create(['company_id' => $companyA->id, 'code' => 'MAY']);
    BpCategory::factory()->create(['company_id' => $companyB->id, 'code' => 'MAY']);

    logInAsCompanyUser($companyA);

    $this->get(route('bp-categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('BusinessPartners/Categories')
            ->has('categories', 1)
            ->where('categories.0.code', $catA->code)
        );
});

it('crea una categoría de socios de negocio', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('bp-categories.store'), [
        'code' => 'MAY',
        'name' => 'Mayorista',
    ])->assertSessionHasNoErrors();

    $category = BpCategory::where('company_id', $company->id)->sole();
    expect($category->code)->toBe('MAY')
        ->and($category->name)->toBe('Mayorista');
});

it('rechaza un código duplicado dentro de la misma compañía', function () {
    ['company' => $company] = logInAsCompanyUser();
    BpCategory::factory()->create(['company_id' => $company->id, 'code' => 'MAY']);

    $this->post(route('bp-categories.store'), [
        'code' => 'MAY',
        'name' => 'Repetido',
    ])->assertSessionHasErrors('code');

    expect(BpCategory::where('company_id', $company->id)->count())->toBe(1);
});

it('permite el mismo código de categoría en compañías distintas', function () {
    $companyB = Company::factory()->create();
    BpCategory::factory()->create(['company_id' => $companyB->id, 'code' => 'MAY']);

    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('bp-categories.store'), [
        'code' => 'MAY',
        'name' => 'Mayorista',
    ])->assertSessionHasNoErrors();

    expect(BpCategory::where('company_id', $company->id)->where('code', 'MAY')->exists())->toBeTrue();
});

it('edita una categoría existente', function () {
    ['company' => $company] = logInAsCompanyUser();
    $category = BpCategory::factory()->create(['company_id' => $company->id, 'code' => 'MAY', 'name' => 'Viejo nombre']);

    $this->put(route('bp-categories.update', $category->id), [
        'code' => 'MAY',
        'name' => 'Mayorista al por mayor',
    ])->assertSessionHasNoErrors();

    expect($category->fresh()->name)->toBe('Mayorista al por mayor');
});

it('rechaza actualizar una categoría de otra compañía', function () {
    $companyB = Company::factory()->create();
    $categoryB = BpCategory::factory()->create(['company_id' => $companyB->id, 'code' => 'MAY']);

    logInAsCompanyUser();

    $this->put(route('bp-categories.update', $categoryB->id), [
        'code' => 'MAY',
        'name' => 'Intento ajeno',
    ])->assertNotFound();
});

it('elimina una categoría sin socios de negocio asignados', function () {
    ['company' => $company] = logInAsCompanyUser();
    $category = BpCategory::factory()->create(['company_id' => $company->id, 'code' => 'MAY']);

    $this->delete(route('bp-categories.destroy', $category->id))->assertSessionHasNoErrors();

    expect(BpCategory::find($category->id))->toBeNull();
});

it('rechaza eliminar una categoría asignada a un socio de negocio', function () {
    ['company' => $company] = logInAsCompanyUser();
    $category = BpCategory::factory()->create(['company_id' => $company->id, 'code' => 'MAY']);
    $cxc = ChartOfAccount::factory()->create(['company_id' => $company->id]);
    BusinessPartner::create([
        'company_id' => $company->id, 'code' => 'C-001', 'name' => 'Cliente', 'type' => 'client',
        'category_id' => $category->id, 'gl_account_id' => $cxc->id, 'currency_id' => $company->local_currency_id,
    ]);

    $this->delete(route('bp-categories.destroy', $category->id))->assertSessionHasErrors('bp_category');

    expect(BpCategory::find($category->id))->not->toBeNull();
});
