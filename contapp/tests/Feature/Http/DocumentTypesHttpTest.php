<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\DocumentTypeNumberSeries;

it('solo lista los tipos de documento de la compañía activa', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $dtA = DocumentType::factory()->create(['company_id' => $companyA->id, 'code' => 'FVE']);
    DocumentType::factory()->create(['company_id' => $companyB->id, 'code' => 'FVE']);

    logInAsCompanyUser($companyA);

    $this->get(route('document-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('DocumentTypes/Index')
            ->has('documentTypes', 1)
            ->where('documentTypes.0.code', $dtA->code)
        );
});

it('crea un tipo de documento con el código en mayúsculas y el consecutivo en 1 por defecto', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('document-types.store'), [
        'code' => 'fve',
        'name' => 'Factura de venta',
        'origin_module' => 'ventas',
        'currency_mode' => 'libre',
        'generates_journal' => true,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    $dt = DocumentType::where('company_id', $company->id)->where('code', 'FVE')->sole();
    expect($dt->next_consecutive)->toBe(1);
});

it('crea un tipo de documento que exige clave numérica electrónica', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('document-types.store'), [
        'code' => 'FCE',
        'name' => 'Factura de compra electrónica',
        'origin_module' => 'compras',
        'currency_mode' => 'libre',
        'requires_electronic_key' => true,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    $dt = DocumentType::where('company_id', $company->id)->where('code', 'FCE')->sole();
    expect($dt->requires_electronic_key)->toBeTrue();
});

it('edita un tipo de documento para exigir clave numérica electrónica', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FCE', 'requires_electronic_key' => false]);

    $this->put(route('document-types.update', $dt->id), [
        'name' => $dt->name,
        'origin_module' => $dt->origin_module,
        'currency_mode' => $dt->currency_mode,
        'requires_electronic_key' => true,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($dt->fresh()->requires_electronic_key)->toBeTrue();
});

it('crea un tipo de documento con un protocolo de control de socio de negocio', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('document-types.store'), [
        'code' => 'TRB',
        'name' => 'Transferencia bancaria',
        'origin_module' => 'bancos',
        'currency_mode' => 'libre',
        'bp_line_requirement' => 'application',
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    $dt = DocumentType::where('company_id', $company->id)->where('code', 'TRB')->sole();
    expect($dt->bp_line_requirement)->toBe('application');
});

it('un tipo de documento sin protocolo indicado queda en "none" por defecto', function () {
    logInAsCompanyUser();

    $this->post(route('document-types.store'), [
        'code' => 'FVE',
        'name' => 'Factura de venta',
        'origin_module' => 'ventas',
        'currency_mode' => 'libre',
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(DocumentType::where('code', 'FVE')->sole()->bp_line_requirement)->toBe('none');
});

it('rechaza un protocolo de control de socio de negocio que no es uno de los valores válidos', function () {
    logInAsCompanyUser();

    $this->post(route('document-types.store'), [
        'code' => 'TRB',
        'name' => 'Transferencia bancaria',
        'origin_module' => 'bancos',
        'currency_mode' => 'libre',
        'bp_line_requirement' => 'invalido',
        'status' => 'active',
    ])->assertSessionHasErrors('bp_line_requirement');
});

it('edita el protocolo de control de socio de negocio de un tipo de documento', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB', 'bp_line_requirement' => 'none']);

    $this->put(route('document-types.update', $dt->id), [
        'name' => $dt->name,
        'origin_module' => $dt->origin_module,
        'currency_mode' => $dt->currency_mode,
        'bp_line_requirement' => 'either',
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($dt->fresh()->bp_line_requirement)->toBe('either');
});

it('respeta un consecutivo inicial indicado al crear', function () {
    logInAsCompanyUser();

    $this->post(route('document-types.store'), [
        'code' => 'FVE',
        'name' => 'Factura de venta',
        'origin_module' => 'ventas',
        'currency_mode' => 'libre',
        'starting_consecutive' => 501,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(DocumentType::where('code', 'FVE')->sole()->next_consecutive)->toBe(501);
});

it('rechaza un código que no sean exactamente 3 letras', function () {
    logInAsCompanyUser();

    $this->post(route('document-types.store'), [
        'code' => 'FVEX',
        'name' => 'Inválido',
        'origin_module' => 'ventas',
        'currency_mode' => 'libre',
        'status' => 'active',
    ])->assertSessionHasErrors('code');
});

it('rechaza un código duplicado dentro de la misma compañía', function () {
    ['company' => $company] = logInAsCompanyUser();
    DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $this->post(route('document-types.store'), [
        'code' => 'FVE',
        'name' => 'Otra',
        'origin_module' => 'ventas',
        'currency_mode' => 'libre',
        'status' => 'active',
    ])->assertSessionHasErrors('code');
});

it('actualiza un tipo de documento sin permitir cambiar el código ni el consecutivo interno', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create([
        'company_id' => $company->id, 'code' => 'FVE', 'name' => 'Viejo nombre', 'next_consecutive' => 42,
    ]);

    $this->put(route('document-types.update', $dt->id), [
        'code' => 'XXX',
        'name' => 'Factura de venta',
        'origin_module' => 'ventas',
        'currency_mode' => 'libre',
        'status' => 'active',
        'next_consecutive' => 9999,
    ])->assertSessionHasNoErrors();

    $dt->refresh();
    expect($dt->name)->toBe('Factura de venta')
        ->and($dt->code)->toBe('FVE')
        ->and($dt->next_consecutive)->toBe(42);
});

it('rechaza editar un tipo de documento de otra compañía', function () {
    $companyB = Company::factory()->create();
    $dtB = DocumentType::factory()->create(['company_id' => $companyB->id, 'code' => 'FVE']);

    logInAsCompanyUser();

    $this->put(route('document-types.update', $dtB->id), [
        'name' => 'Intento ajeno', 'origin_module' => 'ventas', 'currency_mode' => 'libre', 'status' => 'active',
    ])->assertNotFound();
});

// --- series de numeración ---

it('crea una serie con next_number igual al número inicial', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $this->post(route('document-type-number-series.store', $dt->id), [
        'name' => 'Serie A', 'range_from' => 100, 'range_to' => 200,
    ])->assertSessionHasNoErrors();

    $series = DocumentTypeNumberSeries::where('document_type_id', $dt->id)->sole();
    expect($series->next_number)->toBe(100);
});

it('rechaza una serie con número final menor al inicial', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);

    $this->post(route('document-type-number-series.store', $dt->id), [
        'name' => 'Serie A', 'range_from' => 200, 'range_to' => 100,
    ])->assertSessionHasErrors('range_to');
});

it('rechaza un nombre de serie duplicado dentro del mismo tipo de documento', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    DocumentTypeNumberSeries::factory()->create(['company_id' => $company->id, 'document_type_id' => $dt->id, 'name' => 'Serie A']);

    $this->post(route('document-type-number-series.store', $dt->id), [
        'name' => 'Serie A', 'range_from' => 1, 'range_to' => 100,
    ])->assertSessionHasErrors('name');
});

it('guarda a quién se le entrega la serie (talonario físico)', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB']);

    $this->post(route('document-type-number-series.store', $dt->id), [
        'name' => 'PJ01', 'holder_name' => 'Pedro Jiménez', 'range_from' => 2000, 'range_to' => 2050,
    ])->assertSessionHasNoErrors();

    $series = DocumentTypeNumberSeries::where('document_type_id', $dt->id)->sole();
    expect($series->holder_name)->toBe('Pedro Jiménez');
});

it('permite crear una segunda serie para el mismo encargado cuando se le acaba el talonario', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB']);
    DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $dt->id,
        'name' => 'PJ01', 'holder_name' => 'Pedro Jiménez', 'range_from' => 2000, 'range_to' => 2050, 'next_number' => 2051,
    ]);

    $this->post(route('document-type-number-series.store', $dt->id), [
        'name' => 'PJ02', 'holder_name' => 'Pedro Jiménez', 'range_from' => 2100, 'range_to' => 2150,
    ])->assertSessionHasNoErrors();

    expect(DocumentTypeNumberSeries::where('document_type_id', $dt->id)->where('holder_name', 'Pedro Jiménez')->count())->toBe(2);
});

it('edita el encargado de una serie', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'TRB']);
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $dt->id, 'holder_name' => 'María López',
    ]);

    $this->put(route('document-type-number-series.update', $series->id), [
        'name' => $series->name, 'holder_name' => 'María López Solano', 'range_to' => $series->range_to, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($series->fresh()->holder_name)->toBe('María López Solano');
});

it('edita nombre, número final y estado de una serie sin tocar el número inicial', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $dt->id,
        'range_from' => 1, 'range_to' => 100, 'next_number' => 1,
    ]);

    $this->put(route('document-type-number-series.update', $series->id), [
        'name' => 'Serie A renombrada', 'range_to' => 500, 'is_active' => false,
    ])->assertSessionHasNoErrors();

    $series->refresh();
    expect($series->name)->toBe('Serie A renombrada')
        ->and($series->range_from)->toBe(1)
        ->and($series->range_to)->toBe(500)
        ->and($series->is_active)->toBeFalse();
});

it('rechaza achicar el número final de una serie por debajo de lo ya asignado', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $dt->id,
        'range_from' => 1, 'range_to' => 100, 'next_number' => 50,
    ]);

    $this->put(route('document-type-number-series.update', $series->id), [
        'name' => $series->name, 'range_to' => 10, 'is_active' => true,
    ])->assertSessionHasErrors('range_to');
});

it('elimina una serie sin usar', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $series = DocumentTypeNumberSeries::factory()->create(['company_id' => $company->id, 'document_type_id' => $dt->id]);

    $this->delete(route('document-type-number-series.destroy', $series->id))->assertSessionHasNoErrors();

    expect(DocumentTypeNumberSeries::find($series->id))->toBeNull();
});

it('rechaza eliminar una serie que ya asignó números', function () {
    ['company' => $company] = logInAsCompanyUser();
    $dt = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'FVE']);
    $series = DocumentTypeNumberSeries::factory()->create([
        'company_id' => $company->id, 'document_type_id' => $dt->id,
        'range_from' => 1, 'range_to' => 100, 'next_number' => 2,
    ]);

    $this->delete(route('document-type-number-series.destroy', $series->id))->assertSessionHasErrors('series');

    expect(DocumentTypeNumberSeries::find($series->id))->not->toBeNull();
});

it('rechaza gestionar una serie de otra compañía', function () {
    $companyB = Company::factory()->create();
    $dtB = DocumentType::factory()->create(['company_id' => $companyB->id, 'code' => 'FVE']);
    $seriesB = DocumentTypeNumberSeries::factory()->create(['company_id' => $companyB->id, 'document_type_id' => $dtB->id]);

    logInAsCompanyUser();

    $this->post(route('document-type-number-series.store', $dtB->id), [
        'name' => 'Intento ajeno', 'range_from' => 1, 'range_to' => 10,
    ])->assertNotFound();

    $this->put(route('document-type-number-series.update', $seriesB->id), [
        'name' => 'X', 'range_to' => 10, 'is_active' => true,
    ])->assertNotFound();

    $this->delete(route('document-type-number-series.destroy', $seriesB->id))->assertNotFound();
});
