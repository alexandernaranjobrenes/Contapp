<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Models\ContiAction;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use App\Domains\Inventory\Models\PurchaseOrder;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeNote;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollInput;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\VacationMovement;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Cada acción de Conti, de punta a punta
|--------------------------------------------------------------------------
|
| Preparar por la API y confirmar en CONTAPP guarda lo mismo que guardaría
| la pantalla correspondiente.
|
*/

/** Prepara y confirma; devuelve la acción ya decidida. */
function contiRun(array $f, string $action, array $data): ContiAction
{
    $response = contiPrepare($f, $action, $data);
    $response->assertCreated();

    test()->actingAs($f['user'])->post(route('conti.actions.confirm', $response->json('id')));

    return ContiAction::where('uuid', $response->json('id'))->first();
}

function contiSuperFixture(): array
{
    return contiUser([], null, true);
}

function withoutScope(string $model)
{
    return $model::withoutGlobalScopes();
}

it('actualizar un socio cambia solo lo pedido y muestra el antes y el después', function () {
    $f = contiSuperFixture();
    // La cuenta de control, de esta compañía (el factory la crea en otra).
    $account = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'code' => 'C-001', 'name' => 'Viejo', 'credit_limit' => 100, 'gl_account_id' => $account->id]);

    $prepared = contiPrepare($f, 'actualizar_socio', ['socio' => 'C-001', 'nombre' => 'Nuevo', 'plazo_dias' => 45])
        ->assertCreated();

    expect(collect($prepared->json('resumen.datos'))->pluck('valor', 'campo')->all())
        ->toMatchArray(['Nombre' => 'Viejo → Nuevo', 'Plazo de pago' => '— → 45 días']);

    test()->actingAs($f['user'])->post(route('conti.actions.confirm', $prepared->json('id')));

    $partner = withoutScope(BusinessPartner::class)->where('code', 'C-001')->first();
    expect($partner->name)->toBe('Nuevo')
        ->and($partner->payment_terms_days)->toBe(45)
        ->and((float) $partner->credit_limit)->toBe(100.0);
});

it('registrar un tipo de cambio lo crea, o reemplaza el de esa fecha', function () {
    $f = contiSuperFixture();

    expect(contiRun($f, 'registrar_tipo_cambio', ['fecha' => '2026-10-01', 'tipo' => 'venta', 'tasa' => 512.5])->status)->toBe('confirmed');
    contiRun($f, 'registrar_tipo_cambio', ['fecha' => '2026-10-01', 'tipo' => 'sell', 'tasa' => 513, 'bloquear' => 'sí']);

    $rates = withoutScope(ExchangeRate::class)->where('company_id', $f['company']->id)->get();
    expect($rates)->toHaveCount(1)
        ->and((string) $rates->first()->rate)->toStartWith('513')
        ->and((bool) $rates->first()->is_locked)->toBeTrue()
        ->and($rates->first()->source)->toBe('manual');
});

it('crear un centro de costo y una cuenta contable', function () {
    $f = contiSuperFixture();

    contiRun($f, 'crear_centro_costo', ['codigo' => 'VEN', 'nombre' => 'Ventas']);
    contiRun($f, 'crear_cuenta', ['codigo' => '6-01-001', 'nombre' => 'Gasto de papelería', 'clase' => 'gastos', 'exige_norma_reparto' => 'sí']);

    $center = withoutScope(CostCenter::class)->where('code', 'VEN')->first();
    $account = withoutScope(ChartOfAccount::class)->where('code', '6-01-001')->first();

    expect($center->is_active)->toBeTruthy()
        ->and($center->start_date->format('Y-m-d'))->toBe(now()->format('Y-m-d'))
        ->and($account->account_type)->toBe('expense')
        ->and($account->normal_balance)->toBe('debit')
        ->and((bool) $account->requires_cost_center)->toBeTrue()
        ->and((bool) $account->accepts_posting)->toBeTrue();
});

it('cambiar precios de una lista: pone, cambia y quita', function () {
    $f = contiSuperFixture();
    $list = PriceList::factory()->create(['company_id' => $f['company']->id, 'code' => 'LP-1', 'currency_id' => $f['company']->local_currency_id]);
    $a = Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'A-1']);
    $b = Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'B-1']);
    PriceListItem::create(['price_list_id' => $list->id, 'item_id' => $b->id, 'unit_price' => 50]);

    contiRun($f, 'actualizar_precios', ['lista' => 'LP-1', 'precios' => [['articulo' => 'A-1', 'precio' => 1500], ['articulo' => 'B-1', 'precio' => null]]]);

    expect(PriceListItem::where('price_list_id', $list->id)->pluck('unit_price', 'item_id')->map(fn ($p) => (float) $p)->all())
        ->toBe([$a->id => 1500.0]);
});

it('crear una orden de compra con el almacén predeterminado', function () {
    $f = contiSuperFixture();
    $payable = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    BusinessPartner::factory()->supplier()->create(['company_id' => $f['company']->id, 'code' => 'P-001', 'gl_account_id' => $payable->id]);
    $warehouse = Warehouse::factory()->create(['company_id' => $f['company']->id, 'is_default' => true]);
    Item::factory()->create(['company_id' => $f['company']->id, 'code' => 'A-1']);

    $action = contiRun($f, 'crear_orden_compra', ['proveedor' => 'P-001', 'lineas' => [['articulo' => 'A-1', 'cantidad' => 10, 'costo' => 250]]]);

    $order = withoutScope(PurchaseOrder::class)->with('lines')->first();
    expect($action->status)->toBe('confirmed')
        ->and($order->lines)->toHaveCount(1)
        ->and($order->lines->first()->warehouse_id)->toBe($warehouse->id)
        ->and((float) $order->lines->first()->quantity)->toBe(10.0);
});

it('en planillas: anotar, registrar un movimiento del período y registrar vacaciones', function () {
    $f = contiSuperFixture();
    $employee = Employee::create([
        'company_id' => $f['company']->id, 'code' => 'E-001', 'identification_type' => 'cedula', 'identification_number' => '1-1111-1111',
        'first_name' => 'Ana', 'last_name1' => 'Mora', 'hire_date' => '2024-01-01', 'salary_type' => 'mensual', 'base_salary' => '800000', 'status' => 'active',
    ]);
    PayrollConcept::create(['company_id' => $f['company']->id, 'code' => 'HEX', 'name' => 'Horas extra', 'type' => 'earning', 'sign' => 1, 'calculation' => 'hours', 'factor' => 1.5, 'status' => 'active']);
    $period = PayrollPeriod::create([
        'company_id' => $f['company']->id, 'year' => 2026, 'frequency' => 'mensual', 'number' => 10, 'name' => 'Octubre 2026',
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'payment_date' => '2026-10-31', 'status' => 'open',
    ]);
    VacationMovement::create(['company_id' => $f['company']->id, 'employee_id' => $employee->id, 'type' => 'accrual', 'movement_date' => '2026-09-30', 'days' => 5]);

    contiRun($f, 'anotar_empleado', ['empleado' => 'E-001', 'categoria' => 'reconocimiento', 'titulo' => 'Cierre', 'detalle' => 'Excelente cierre de mes']);
    contiRun($f, 'registrar_movimiento_planilla', ['periodo' => 'Octubre 2026', 'empleado' => 'E-001', 'concepto' => 'HEX', 'horas' => 4]);
    contiRun($f, 'registrar_vacaciones', ['empleado' => 'E-001', 'tipo' => 'disfrute', 'dias' => 2]);

    expect(withoutScope(EmployeeNote::class)->first()->category)->toBe('recognition')
        ->and((float) withoutScope(PayrollInput::class)->where('payroll_period_id', $period->id)->first()->quantity)->toBe(4.0)
        ->and((float) withoutScope(VacationMovement::class)->where('type', 'taken')->first()->days)->toBe(-2.0);

    // Un concepto por horas sin horas, y un disfrute que deja el saldo en negativo, no se preparan.
    contiPrepare($f, 'registrar_movimiento_planilla', ['periodo' => $period->id, 'empleado' => 'E-001', 'concepto' => 'HEX', 'monto' => 1000])
        ->assertStatus(422)->assertJsonValidationErrors(['horas']);
    contiPrepare($f, 'registrar_vacaciones', ['empleado' => 'E-001', 'tipo' => 'disfrute', 'dias' => 10])
        ->assertStatus(422)->assertJsonValidationErrors(['dias']);
});
