<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Services\CalculatePayrollService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Payroll\Services\PayslipDocument;
use App\Mail\PayslipMail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * El comprobante de pago: PDF y envío por correo.
 *
 * Fixture propio del archivo: los helpers de Pest comparten un espacio de
 * nombres global. Se calcula una planilla de verdad porque el comprobante vale
 * por lo que muestra de la boleta, y una boleta inventada a mano no lo prueba.
 */
function payslipFixture(array $employeeOverrides = []): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2020-01-01');

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    PayrollSetting::create([
        'company_id' => $company->id,
        'salary_expense_account_id' => ChartOfAccount::factory()->create([
            'company_id' => $company->id, 'account_type' => 'expense', 'accepts_posting' => true,
        ])->id,
        'vacation_days_per_month' => '1',
        'max_deduction_percentage' => '0',
    ]);

    $employee = Employee::create(array_merge([
        'company_id' => $company->id,
        'code' => 'CP1',
        'identification_type' => 'cedula',
        'identification_number' => '110000001',
        'ccss_number' => 'SEG-CP1',
        'first_name' => 'Compro',
        'last_name1' => 'Bante',
        'email' => 'trabajador@ejemplo.test',
        'hire_date' => '2020-01-01',
        'cost_center_id' => $costCenter->id,
        'journey_type' => 'diurna',
        'weekly_hours' => '48',
        'salary_type' => 'mensual',
        'base_salary' => '900000.00',
        'payment_method' => 'transferencia',
        'bank_account' => 'CR01000000000000001',
        'status' => 'active',
    ], $employeeOverrides));

    $period = PayrollPeriod::create([
        'company_id' => $company->id,
        'year' => 2026,
        'frequency' => 'mensual',
        'number' => 4,
        'name' => 'Abril 2026',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
        'payment_date' => '2026-04-30',
        'status' => 'open',
    ]);

    app(CalculatePayrollService::class)->calculate($company, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    return compact('company', 'employee', 'period', 'entry');
}

// ── El documento ────────────────────────────────────────────────────────

it('arma los datos del comprobante una sola vez para las cuatro salidas', function () {
    $f = payslipFixture();

    $payload = app(PayslipDocument::class)->payload($f['company'], $f['entry']);

    // Lo que hace verificable un comprobante: la base y la tasa de cada
    // rebajo, no solo el monto.
    $contribution = collect($payload['deductions'])->firstWhere('kind', 'employee_contribution');

    expect($contribution)->not->toBeNull()
        ->and($contribution['base_amount'])->not->toBeNull()
        ->and($contribution['rate'])->toBeGreaterThan(0);

    // Y el bloque patronal, que no rebaja nada y por eso nadie lo ve.
    expect($payload['employerLines'])->not->toBeEmpty()
        ->and((float) $payload['entry']['employer_cost'])
        ->toBeGreaterThan((float) $payload['entry']['total_earnings']);
});

it('la pantalla y el PDF salen del mismo payload', function () {
    $f = payslipFixture();

    $this->get(route('payslips.show', $f['entry']->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Payroll/Payslips/Show')
            ->where('entry.id', $f['entry']->id)
            ->has('earnings')
            ->has('deductions')
        );

    $pdf = $this->get(route('payslips.pdf', $f['entry']->id));

    $pdf->assertOk();

    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        // El nombre lleva código y período: es lo que se busca en una carpeta
        // con cien comprobantes.
        ->and($pdf->headers->get('Content-Disposition'))->toContain('comprobante-cp1-abril-2026.pdf');
});

it('el PDF lleva el logo aunque esté en un bucket y no en una carpeta', function () {
    Storage::fake('s3');
    config(['filesystems.media' => 's3']);

    $f = payslipFixture();

    $logo = UploadedFile::fake()->image('logo.png', 60, 60);
    Storage::disk('s3')->put('logos/logo.png', $logo->getContent());
    $f['company']->update(['logo_path' => 'logos/logo.png']);

    // La pantalla recibe la dirección pública; la ruta del servidor no viaja.
    $payload = app(PayslipDocument::class)->payload($f['company'], $f['entry']);

    expect($payload['company']['logo_url'])->toBe(Storage::disk('s3')->url('logos/logo.png'))
        ->and($payload['company'])->not->toHaveKey('logo_path');

    // El PDF lo lleva incrustado: dompdf no baja imágenes por HTTP.
    $payload['company']['logo_data_uri'] = app(MediaStorage::class)->dataUri('logos/logo.png');

    expect(view('reports.payslip', $payload)->render())
        ->toContain('src="data:image/png;base64,'.base64_encode($logo->getContent()).'"');

    $this->get(route('payslips.pdf', $f['entry']->id))->assertOk();
});

// ── El envío por correo ─────────────────────────────────────────────────

it('encola el comprobante al correo de la ficha', function () {
    Mail::fake();

    $f = payslipFixture();

    $this->post(route('payslips.email', $f['entry']->id))->assertSessionHasNoErrors();

    Mail::assertQueued(PayslipMail::class, fn (PayslipMail $mail) => $mail->hasTo('trabajador@ejemplo.test'));
});

it('no envía nada si la ficha no tiene correo, y dice de quién es', function () {
    Mail::fake();

    $f = payslipFixture(['email' => null]);

    $this->post(route('payslips.email', $f['entry']->id))
        ->assertSessionHasErrors('payroll');

    // El único arreglo posible es llenarle la ficha, así que se nombra.
    expect(session('errors')->first('payroll'))->toContain('Compro Bante');

    Mail::assertNothingQueued();
});

it('el correo lleva el PDF adjunto y el neto en el cuerpo', function () {
    $f = payslipFixture();

    $mail = new PayslipMail($f['company']->id, $f['entry']->id);

    $rendered = $mail->render();

    expect($rendered)->toContain('Abril 2026')
        ->and($rendered)->toContain('Comprobante de pago');

    $attachments = $mail->attachments();

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->as)->toBe('comprobante-cp1-abril-2026.pdf');
});

it('el envío masivo manda los que puede y nombra a los que no', function () {
    Mail::fake();

    $f = payslipFixture();

    // Un segundo trabajador SIN correo: que a él no le llegue no puede impedir
    // que al primero sí.
    $sinCorreo = Employee::create([
        'company_id' => $f['company']->id,
        'code' => 'CP2',
        'identification_type' => 'cedula',
        'identification_number' => '110000002',
        'first_name' => 'Sin',
        'last_name1' => 'Correo',
        'hire_date' => '2020-01-01',
        'journey_type' => 'diurna',
        'weekly_hours' => '48',
        'salary_type' => 'mensual',
        'base_salary' => '500000.00',
        'payment_method' => 'efectivo',
        'status' => 'active',
    ]);

    app(CalculatePayrollService::class)->calculate($f['company'], $f['period']->fresh());

    $this->post(route('payroll-periods.email-payslips', $f['period']->id))
        ->assertSessionHasNoErrors();

    Mail::assertQueued(PayslipMail::class, 1);

    expect(session('success'))->toContain('1 comprobante(s)')
        ->and(session('success'))->toContain('CP2 Sin Correo')
        ->and($sinCorreo->email)->toBeNull();
});

it('no manda comprobantes de un período que todavía no se calculó', function () {
    Mail::fake();

    $f = payslipFixture();

    $abierto = PayrollPeriod::create([
        'company_id' => $f['company']->id,
        'year' => 2026,
        'frequency' => 'mensual',
        'number' => 5,
        'name' => 'Mayo 2026',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'payment_date' => '2026-05-31',
        'status' => 'open',
    ]);

    $this->post(route('payroll-periods.email-payslips', $abierto->id))
        ->assertSessionHasErrors('payroll');

    Mail::assertNothingQueued();
});

it('enviar un comprobante exige permiso de escritura', function () {
    Mail::fake();

    $f = payslipFixture();

    // Sale información salarial de la empresa hacia afuera: es una acción, no
    // una consulta.
    $otro = User::factory()->create(['default_company_id' => $f['company']->id]);
    $f['company']->users()->attach($otro->id, ['is_default' => true]);

    $this->actingAs($otro)
        ->post(route('payslips.email', $f['entry']->id))
        ->assertForbidden();

    Mail::assertNothingQueued();
});

it('no deja mandar el comprobante de otra compañía', function () {
    Mail::fake();

    $f = payslipFixture();
    $otra = payslipFixture();

    // La segunda sesión quedó activa; la boleta de la primera no le pertenece.
    //
    // `payroll_entries` no tiene company_id —lo hereda del período— así que el
    // modelo no lleva el scope de compañía: un findOrFail($id) encontraba la
    // boleta de cualquier empresa. Las cuatro salidas tienen que cerrarlo, no
    // solo el correo.
    $this->post(route('payslips.email', $f['entry']->id))->assertNotFound();
    $this->get(route('payslips.show', $f['entry']->id))->assertNotFound();
    $this->get(route('payslips.print', $f['entry']->id))->assertNotFound();
    $this->get(route('payslips.pdf', $f['entry']->id))->assertNotFound();

    // Y la propia sí se puede abrir: la prueba no pasa por bloquear todo.
    $this->get(route('payslips.show', $otra['entry']->id))->assertOk();

    Mail::assertNothingQueued();

    // Y el documento lo rechaza también en el nivel del servicio, que es el
    // que corre en la cola sin compañía ambiental.
    expect(fn () => app(PayslipDocument::class)->findEntry($otra['company'], $f['entry']->id))
        ->toThrow(InvalidPayrollException::class);
});
