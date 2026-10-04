<?php

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseActivationService;

/*
|--------------------------------------------------------------------------
| Mi cuenta → Tus compañías: los datos de una compañía
|--------------------------------------------------------------------------
|
| Razón social, nombre comercial y cédula jurídica. Los cambia solo el
| Superusuario de esa compañía (CompanyDetailsController).
|
*/

/** Una compañía con licencia y su Superusuario, con la sesión iniciada. */
function companyOwner(array $company = []): array
{
    $activation = app(LicenseActivationService::class)->activate(
        License::factory()->create(['max_companies' => 2])->code,
        array_merge(['legal_name' => 'Ferretería El Clavo S.A.', 'trade_name' => 'El Clavo', 'tax_id' => '3-101-123456'], $company),
        ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Secreto#1234'],
    );

    test()->actingAs($activation['user']);

    return $activation;
}

it('el Superusuario ve los datos de sus compañías y puede editarlos', function () {
    ['company' => $company] = companyOwner();

    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page
        ->where('memberships.0.id', $company->id)
        ->where('memberships.0.can_edit', true)
        ->where('memberships.0.legal_name', 'Ferretería El Clavo S.A.')
        ->where('memberships.0.trade_name', 'El Clavo')
        ->where('memberships.0.tax_id', '3-101-123456'));
});

it('cambia la razón social, el nombre comercial y la cédula, y lo deja en la bitácora', function () {
    ['company' => $company, 'user' => $owner] = companyOwner();

    $this->put(route('profile.companies.update', $company->id), [
        'legal_name' => '  Ferretería El Clavo Dorado S.A.  ',
        'trade_name' => 'Clavo Dorado',
        'tax_id' => '3-101-654321',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $company->refresh();

    expect($company->legal_name)->toBe('Ferretería El Clavo Dorado S.A.')
        ->and($company->trade_name)->toBe('Clavo Dorado')
        ->and($company->tax_id)->toBe('3-101-654321');

    $log = AuditLog::where('action', 'company.details_updated')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->user_id)->toBe($owner->id)
        ->and($log->old_values)->toBe(['legal_name' => 'Ferretería El Clavo S.A.', 'trade_name' => 'El Clavo', 'tax_id' => '3-101-123456'])
        ->and($log->new_values)->toBe(['legal_name' => 'Ferretería El Clavo Dorado S.A.', 'trade_name' => 'Clavo Dorado', 'tax_id' => '3-101-654321']);

    // El selector de compañía y el resto de las pantallas ya muestran el nombre nuevo.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('companies.0.trade_name', 'Clavo Dorado'));
});

it('la cédula es opcional, y sin nombre comercial se usa la razón social, igual que al crearla', function () {
    ['company' => $company] = companyOwner();

    $this->put(route('profile.companies.update', $company->id), [
        'legal_name' => 'Ferretería El Clavo S.A.',
        'trade_name' => '',
        'tax_id' => '',
    ])->assertSessionHasNoErrors();

    $company->refresh();

    expect($company->trade_name)->toBe('Ferretería El Clavo S.A.')
        ->and($company->tax_id)->toBeNull();

    // En el formulario, ese nombre comercial se muestra vacío: no se puso uno.
    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('memberships.0.trade_name', ''));
});

it('guardar sin cambios no llena la bitácora', function () {
    ['company' => $company] = companyOwner();

    $this->put(route('profile.companies.update', $company->id), [
        'legal_name' => 'Ferretería El Clavo S.A.',
        'trade_name' => 'El Clavo',
        'tax_id' => '3-101-123456',
    ])->assertSessionHasNoErrors();

    expect(AuditLog::where('action', 'company.details_updated')->exists())->toBeFalse();
});

it('exige la razón social y no acepta textos de más', function (array $payload, string $field) {
    ['company' => $company] = companyOwner();

    $this->put(route('profile.companies.update', $company->id), $payload)->assertSessionHasErrors($field);

    expect($company->fresh()->legal_name)->toBe('Ferretería El Clavo S.A.');
})->with([
    'sin razón social' => [['legal_name' => '', 'trade_name' => 'X'], 'legal_name'],
    'razón social de más' => [['legal_name' => str_repeat('a', 256)], 'legal_name'],
    'cédula de más' => [['legal_name' => 'Ferretería', 'tax_id' => str_repeat('1', 51)], 'tax_id'],
]);

it('un Administrador o un Usuario ve sus compañías pero no puede cambiar sus datos', function (string $roleType) {
    ['company' => $company, 'user' => $owner] = companyOwner();

    $member = app(PermissionGrantService::class)->createUser(
        $owner, $company,
        ['name' => 'Beto', 'email' => 'beto@example.com', 'password' => 'Secreto#1234'], $roleType, [],
    );
    $this->actingAs($member);

    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('memberships.0.can_edit', false));

    $this->put(route('profile.companies.update', $company->id), ['legal_name' => 'Tomada S.A.'])->assertForbidden();

    expect($company->fresh()->legal_name)->toBe('Ferretería El Clavo S.A.');
})->with(['admin', 'user']);

it('nadie cambia los datos de una compañía a la que no pertenece', function () {
    ['company' => $company] = companyOwner();
    $other = Company::factory()->create(['legal_name' => 'Ajena S.A.']);

    $this->put(route('profile.companies.update', $other->id), ['legal_name' => 'Tomada S.A.'])->assertForbidden();

    expect($other->fresh()->legal_name)->toBe('Ajena S.A.');
});

it('el dueño de una licencia, invitado a la de otro, no cambia los datos de la ajena', function () {
    ['company' => $own, 'user' => $owner] = companyOwner();

    $other = app(LicenseActivationService::class)->activate(
        License::factory()->create()->code, ['legal_name' => 'Ajena S.A.'],
        ['name' => 'Otra', 'email' => 'otra@example.com', 'password' => 'Secreto#1234'],
    );
    app(PermissionGrantService::class)->inviteUser($other['user'], $other['company'], 'ana@example.com', 'admin', []);
    $this->actingAs($owner);

    $this->put(route('profile.companies.update', $other['company']->id), ['legal_name' => 'Tomada S.A.'])->assertForbidden();
    $this->put(route('profile.companies.update', $own->id), ['legal_name' => 'Propia Renombrada S.A.'])->assertSessionHasNoErrors();

    expect($other['company']->fresh()->legal_name)->toBe('Ajena S.A.')
        ->and($own->fresh()->legal_name)->toBe('Propia Renombrada S.A.');
});

it('se edita una compañía propia aunque no sea la activa', function () {
    ['company' => $first, 'user' => $owner, 'license' => $license] = companyOwner();

    // Una segunda compañía de la misma licencia; la activa sigue siendo la primera.
    $this->post(route('companies.store'), ['legal_name' => 'Segunda S.A.']);
    $second = Company::where('legal_name', 'Segunda S.A.')->sole();

    $this->put(route('profile.companies.update', $second->id), ['legal_name' => 'Segunda Renombrada S.A.'])->assertSessionHasNoErrors();

    expect($second->fresh()->legal_name)->toBe('Segunda Renombrada S.A.');
});

it('con la licencia de esa compañía vencida no se cambian sus datos', function () {
    ['company' => $company, 'license' => $license] = companyOwner();
    $license->update(['expires_at' => now()->subDay()->format('Y-m-d')]);

    $this->put(route('profile.companies.update', $company->id), ['legal_name' => 'Renombrada S.A.'])->assertForbidden();

    expect($company->fresh()->legal_name)->toBe('Ferretería El Clavo S.A.');
});

it('exige sesión', function () {
    $company = Company::factory()->create();

    $this->put(route('profile.companies.update', $company->id), ['legal_name' => 'X'])->assertRedirect(route('login'));
});
