<?php

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

/**
 * Las imágenes subidas van al disco que diga `filesystems.media`, y a ningún
 * otro. Acá el disco es "s3" —el del bucket— reemplazado por uno de mentira:
 * lo que se prueba es que el código le hace caso a la configuración, no que
 * Cloudflare responda.
 */
beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('public');

    config(['filesystems.media' => 's3']);
});

function mediaEmployee(): Employee
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);

    return Employee::create([
        'company_id' => $company->id,
        'code' => 'F1',
        'identification_type' => 'cedula',
        'identification_number' => '310000001',
        'first_name' => 'Foto',
        'last_name1' => 'Grafía',
        'hire_date' => '2020-01-01',
        'salary_type' => 'mensual',
        'base_salary' => '800000.00',
        'status' => 'active',
    ]);
}

function mediaHeader(?string $logoPath): ReportHeader
{
    return new ReportHeader(
        companyName: 'Compañía de Prueba',
        taxId: '3-101-000000',
        address: null,
        logoPath: $logoPath,
        title: 'Balance de comprobación',
        paramsSummary: 'Abril 2026',
        generatedByName: 'Ana',
        generatedAt: Date::now(),
    );
}

it('guarda la fotografía en el disco de imágenes y no en la carpeta de la aplicación', function () {
    $employee = mediaEmployee();

    $this->post(route('employees.photo', $employee->id), [
        'photo' => UploadedFile::fake()->image('foto.jpg', 200, 200),
    ])->assertSessionHasNoErrors();

    $path = $employee->fresh()->photo_path;

    expect($path)->toStartWith("employees/{$employee->company_id}/");

    Storage::disk('s3')->assertExists($path);
    expect(Storage::disk('public')->allFiles())->toBe([]);

    // La pantalla recibe la dirección que da ese mismo disco.
    $this->get(route('employees.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('employee.photo_url', Storage::disk('s3')->url($path)));
});

it('borra la fotografía anterior al reemplazarla', function () {
    $employee = mediaEmployee();

    $this->post(route('employees.photo', $employee->id), ['photo' => UploadedFile::fake()->image('uno.jpg')]);
    $first = $employee->fresh()->photo_path;

    $this->post(route('employees.photo', $employee->id), ['photo' => UploadedFile::fake()->image('dos.jpg')]);
    $second = $employee->fresh()->photo_path;

    expect($second)->not->toBe($first);

    Storage::disk('s3')->assertMissing($first);
    Storage::disk('s3')->assertExists($second);
});

it('conserva la fotografía que había si la nueva no se pudo guardar', function () {
    $employee = mediaEmployee();
    $employee->update(['photo_path' => 'employees/1/anterior.jpg']);

    // El bucket no responde: store() devuelve null y no se borra nada.
    $this->mock(MediaStorage::class, function ($mock) {
        $mock->shouldReceive('store')->once()->andReturnNull();
        $mock->shouldNotReceive('delete');
    });

    $this->post(route('employees.photo', $employee->id), [
        'photo' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertSessionHasErrors('photo');

    expect($employee->fresh()->photo_path)->toBe('employees/1/anterior.jpg');
});

it('incrusta el logo en el encabezado del PDF leyéndolo del disco de imágenes', function () {
    Storage::disk('s3')->put('logos/7/logo.jpg', 'bytes-del-logo');

    $html = view('reports.partials.header', ['header' => mediaHeader('logos/7/logo.jpg')])->render();

    // Los bytes, no una ruta ni una URL: dompdf no baja imágenes por HTTP, y
    // en un bucket no hay ruta en disco que leer.
    expect($html)->toContain('src="data:image/jpeg;base64,'.base64_encode('bytes-del-logo').'"');
});

it('arma el encabezado sin logo cuando el archivo no está en el disco', function () {
    $html = view('reports.partials.header', ['header' => mediaHeader('logos/7/no-existe.png')])->render();

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('Compañía de Prueba');

    // Y sin logo configurado, igual.
    expect(view('reports.partials.header', ['header' => mediaHeader(null)])->render())
        ->not->toContain('<img');
});

it('no pregunta por una imagen que no hay', function () {
    $media = app(MediaStorage::class);

    expect($media->url(null))->toBeNull()
        ->and($media->dataUri(null))->toBeNull();

    $media->delete(null);
});
