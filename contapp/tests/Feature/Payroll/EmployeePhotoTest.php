<?php

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| La foto del empleado, al crearlo y al editarlo
|--------------------------------------------------------------------------
|
| EmployeePhotoService: una por empleado, en el bucket, como
| «companies/employee_photos/company_{compañía}/{nombre}_{id}.{ext}». Al
| cambiarla, quitarla o eliminar al empleado, la anterior no queda.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    config(['filesystems.media' => 's3']);
});

function photoCompany(): int
{
    ['company' => $company] = logInAsCompanyUser();
    app(CurrentCompany::class)->set($company->id);

    return $company->id;
}

function photoEmployeeData(array $override = []): array
{
    return [
        'code' => 'E-100',
        'identification_type' => 'cedula',
        'identification_number' => '112345678',
        'first_name' => 'José',
        'last_name1' => 'Pérez',
        'last_name2' => 'Mora',
        'hire_date' => '2026-01-15',
        'contract_type' => 'indefinido',
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'salary_type' => 'mensual',
        'base_salary' => 900000,
        'payment_method' => 'transferencia',
        'status' => 'active',
        ...$override,
    ];
}

function photoFolder(int $companyId): string
{
    return "companies/employee_photos/company_{$companyId}";
}

it('al crear el empleado guarda su foto con su nombre y su id, cuadrada y sin lo de más', function () {
    $companyId = photoCompany();

    $this->post(route('employees.store'), photoEmployeeData([
        'photo' => UploadedFile::fake()->image('celular.jpg', 1600, 1200),
    ]))->assertSessionHasNoErrors()->assertSessionHas('success');

    $employee = Employee::where('code', 'E-100')->sole();
    $path = photoFolder($companyId)."/jose_perez_mora_{$employee->id}.jpg";

    expect($employee->photo_path)->toBe($path);
    Storage::disk('s3')->assertExists($path);

    // Recortada al cuadrado y reducida, como la foto de perfil.
    [$width, $height] = getimagesizefromstring(Storage::disk('s3')->get($path));
    expect([$width, $height])->toBe([512, 512]);
});

it('al editar: una foto nueva reemplaza a la anterior y no deja basura', function () {
    $companyId = photoCompany();
    $this->post(route('employees.store'), photoEmployeeData(['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]));
    $employee = Employee::where('code', 'E-100')->sole();
    $first = $employee->photo_path;

    // Como la manda el formulario con un archivo: POST con _method=put.
    $this->post(route('employees.update', $employee->id), photoEmployeeData([
        '_method' => 'PUT',
        'photo' => UploadedFile::fake()->image('b.png', 300, 300),
    ]))->assertSessionHasNoErrors();

    $second = $employee->fresh()->photo_path;
    expect($second)->toBe(photoFolder($companyId)."/jose_perez_mora_{$employee->id}.png");
    expect(Storage::disk('s3')->allFiles())->toBe([$second]);
    Storage::disk('s3')->assertMissing($first);

    // Con el mismo formato, se pisa en el mismo lugar.
    $this->post(route('employees.update', $employee->id), photoEmployeeData([
        '_method' => 'PUT',
        'photo' => UploadedFile::fake()->image('c.png', 300, 300),
    ]));
    expect(Storage::disk('s3')->allFiles())->toBe([$second]);
});

it('«Quitar» la borra del bucket, y editar otra cosa no la toca', function () {
    photoCompany();
    $this->post(route('employees.store'), photoEmployeeData(['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]));
    $employee = Employee::where('code', 'E-100')->sole();
    $path = $employee->photo_path;

    $this->put(route('employees.update', $employee->id), photoEmployeeData(['phone' => '8888-0000']))->assertSessionHasNoErrors();
    expect($employee->fresh()->photo_path)->toBe($path);
    Storage::disk('s3')->assertExists($path);

    $this->put(route('employees.update', $employee->id), photoEmployeeData(['remove_photo' => true]))->assertSessionHasNoErrors();
    expect($employee->fresh()->photo_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('si cambia el nombre, la foto se muda a su ruta nueva; las de antes también', function () {
    $companyId = photoCompany();
    $this->post(route('employees.store'), photoEmployeeData(['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]));
    $employee = Employee::where('code', 'E-100')->sole();
    $old = $employee->photo_path;

    $this->put(route('employees.update', $employee->id), photoEmployeeData(['first_name' => 'Josué']))->assertSessionHasNoErrors();

    $new = photoFolder($companyId)."/josue_perez_mora_{$employee->id}.jpg";
    expect($employee->fresh()->photo_path)->toBe($new)
        ->and(Storage::disk('s3')->allFiles())->toBe([$new]);
    Storage::disk('s3')->assertMissing($old);

    // Una foto de antes, con nombre al azar, se acomoda la próxima vez que se guarda.
    Storage::disk('s3')->put("employees/{$companyId}/azar123.jpg", 'bytes');
    $employee->forceFill(['photo_path' => "employees/{$companyId}/azar123.jpg"])->save();
    Storage::disk('s3')->delete($new);

    $this->put(route('employees.update', $employee->id), photoEmployeeData(['first_name' => 'Josué']));
    expect($employee->fresh()->photo_path)->toBe($new)
        ->and(Storage::disk('s3')->allFiles())->toBe([$new]);
});

it('al eliminar al empleado, su foto también se va', function () {
    photoCompany();
    $this->post(route('employees.store'), photoEmployeeData(['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]));
    $employee = Employee::where('code', 'E-100')->sole();

    $this->delete(route('employees.destroy', $employee->id))->assertSessionHasNoErrors();

    expect(Employee::find($employee->id))->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('desde la ficha completa también se quita', function () {
    photoCompany();
    $this->post(route('employees.store'), photoEmployeeData(['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]));
    $employee = Employee::where('code', 'E-100')->sole();

    $this->delete(route('employees.photo.destroy', $employee->id))->assertSessionHasNoErrors();

    expect($employee->fresh()->photo_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('rechaza lo que no es una imagen, y no crea al empleado', function () {
    photoCompany();

    $this->post(route('employees.store'), photoEmployeeData([
        'photo' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf'),
    ]))->assertSessionHasErrors('photo');

    expect(Employee::where('code', 'E-100')->exists())->toBeFalse()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});
