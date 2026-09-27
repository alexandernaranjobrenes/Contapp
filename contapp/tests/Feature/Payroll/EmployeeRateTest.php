<?php

use App\Domains\Payroll\Models\Employee;

/**
 * El valor del día y el de la hora.
 *
 * ── Por qué estos dos números merecen su propio archivo ──────────────────
 *
 * Son el primitivo del módulo. Los días multiplican vacaciones, aguinaldo,
 * preaviso, cesantía e incapacidades; las horas multiplican el devengado y
 * las extra. Un error de un colón acá no se queda en un colón: se reparte por
 * toda la planilla y por toda la liquidación, y siempre en la misma dirección.
 *
 * Los dos ya estuvieron mal una vez —la hora un 15% arriba, el día de los
 * semanales un 15% abajo— y ninguna prueba lo detectó porque no había ninguna
 * que mirara el número directamente.
 *
 * Fixture propio del archivo: los helpers de Pest comparten un espacio de
 * nombres global.
 */
function rateEmployee(array $attributes = []): Employee
{
    // Sin base de datos: el valor del día y el de la hora son aritmética
    // sobre la ficha, y guardarla solo agregaría lentitud a una prueba que
    // vale precisamente por poder correrse a cada rato.
    return new Employee(array_merge([
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'weekly_salary_divisor' => Employee::DEFAULT_WEEKLY_DIVISOR,
        'salary_type' => 'mensual',
        'base_salary' => '400000.00',
    ], $attributes));
}

// ── El día, modalidad por modalidad ──────────────────────────────────────

it('divide el salario mensual entre 30 días', function () {
    // El mes costarricense paga 30 días, incluido el descanso semanal.
    expect(rateEmployee(['salary_type' => 'mensual', 'base_salary' => '400000.00'])->dailyRate())
        ->toBe('13333.33');
});

it('divide el salario quincenal entre 15 días', function () {
    // No entre los días calendario de la quincena: las dos son de 15.
    expect(rateEmployee(['salary_type' => 'quincenal', 'base_salary' => '200000.00'])->dailyRate())
        ->toBe('13333.33');
});

it('divide el salario semanal entre 6 días', function () {
    // El caso del manual de liquidaciones: ₡140.000 a la semana.
    //
    // Antes este salario se mensualizaba (× 4,3333 = ₡606.662) y se dividía
    // entre 30, dando ₡20.222,07 — un 15% de menos. El error era mezclar dos
    // convenciones: 4,3333 semanas son 26 días laborados, no 30.
    expect(rateEmployee(['salary_type' => 'semanal', 'base_salary' => '140000.00'])->dailyRate())
        ->toBe('23333.33');
});

it('divide el salario semanal entre 7 cuando la semana incluye el descanso', function () {
    // Comercio (CT art. 152). Entre este día y el anterior hay un 16%: es la
    // razón por la que el divisor está en la ficha y no fijo en el código.
    expect(rateEmployee([
        'salary_type' => 'semanal', 'base_salary' => '140000.00', 'weekly_salary_divisor' => 7,
    ])->dailyRate())->toBe('20000.00');
});

it('toma el salario diario tal cual, sin dividirlo', function () {
    expect(rateEmployee(['salary_type' => 'diario', 'base_salary' => '15000.00'])->dailyRate())
        ->toBe('15000.00');
});

it('multiplica el salario por hora por las horas de la jornada', function () {
    // Acá el sentido se invierte: el salario ya es la hora, así que el día se
    // arma hacia arriba. Una jornada nocturna de 6 horas da un día más bajo
    // con la misma tarifa, que es lo correcto: trabaja menos horas.
    expect(rateEmployee(['salary_type' => 'hora', 'base_salary' => '2000.00'])->dailyRate())
        ->toBe('16000.00')
        ->and(rateEmployee([
            'salary_type' => 'hora', 'base_salary' => '2000.00', 'journey_type' => 'nocturna',
        ])->dailyRate())->toBe('12000.00');
});

it('cae a 30 días si la modalidad no está en la tabla', function () {
    // Un salary_type que no exista no puede dar una división por cero ni un
    // día en blanco: una planilla a medio calcular es peor que un supuesto.
    expect(rateEmployee(['salary_type' => 'inventado', 'base_salary' => '300000.00'])->dailyRate())
        ->toBe('10000.00');
});

it('no divide entre cero si la ficha trae un divisor semanal vacío', function () {
    expect(rateEmployee([
        'salary_type' => 'semanal', 'base_salary' => '140000.00', 'weekly_salary_divisor' => 0,
    ])->dailyRate())->toBe('23333.33');
});

// ── La hora, derivada del día ────────────────────────────────────────────

it('deriva la hora del día entre las horas de la jornada', function () {
    // ₡400.000 ÷ 30 = ₡13.333,33 el día, ÷ 8 = ₡1.666,67 la hora.
    //
    // Antes se dividía entre las horas semanales por 4,3333 semanas —208 para
    // una semana de 48— y daba ₡1.923,09: un 15% de más en cada hora extra.
    expect(rateEmployee(['base_salary' => '400000.00'])->hourlyRate())->toBe('1666.66');
});

it('paga más la hora de una jornada más corta', function () {
    // Mismo día, menos horas para cumplirlo: la hora vale más (CT art. 136).
    $salary = ['base_salary' => '400000.00'];

    expect(rateEmployee($salary + ['journey_type' => 'mixta'])->hourlyRate())->toBe('1904.76')
        ->and(rateEmployee($salary + ['journey_type' => 'nocturna'])->hourlyRate())->toBe('2222.22');
});

it('devuelve la hora del salario por hora sin rodeos', function () {
    // Pasar por el día y volver introduciría redondeo sin agregar nada.
    expect(rateEmployee(['salary_type' => 'hora', 'base_salary' => '2350.75'])->hourlyRate())
        ->toBe('2350.75');
});

it('la hora del semanal sale de su propio día, no del mes', function () {
    // ₡140.000 ÷ 6 = ₡23.333,33 el día, ÷ 8 = ₡2.916,66 la hora. Con el
    // cálculo viejo daba ₡2.527,75.
    expect(rateEmployee(['salary_type' => 'semanal', 'base_salary' => '140000.00'])->hourlyRate())
        ->toBe('2916.66');
});

it('el día y la hora no se contradicen', function () {
    // La contradicción vieja: dailyRate dividía entre 30 y hourlyRate entre
    // 4,3333 semanas, así que hora × horas de jornada no daba el día. Ahora
    // uno se deriva del otro y la identidad se cumple en toda modalidad.
    foreach ([
        ['salary_type' => 'mensual', 'base_salary' => '600000.00'],
        ['salary_type' => 'quincenal', 'base_salary' => '300000.00'],
        ['salary_type' => 'semanal', 'base_salary' => '140000.00'],
        ['salary_type' => 'diario', 'base_salary' => '24000.00'],
        ['salary_type' => 'hora', 'base_salary' => '3000.00'],
    ] as $attributes) {
        $employee = rateEmployee($attributes);

        // Con la escala de cálculo (6 decimales), no la de pantalla: la de
        // pantalla pierde céntimos por definición.
        $reconstructed = bcmul($employee->hourlyRate(6), $employee->ordinaryHoursPerDay(), 2);

        expect($reconstructed)->toBe($employee->dailyRate(2), "modalidad {$attributes['salary_type']}");
    }
});

it('pide la hora con más decimales para no perder céntimos en cada una', function () {
    $employee = rateEmployee(['base_salary' => '400000.00']);

    // El valor de la hora es un FACTOR, no un monto que se pague: redondearlo
    // antes de multiplicarlo por las horas descuenta siempre hacia abajo.
    expect($employee->hourlyRate(6))->toBe('1666.666666')
        ->and($employee->hourlyRate())->toBe('1666.66');

    // Sobre cuarenta horas la diferencia ya es visible, y es del trabajador.
    $truncated = bcmul($employee->hourlyRate(2), '40', 2);
    $precise = bcmul($employee->hourlyRate(6), '40', 2);

    expect(bccomp($precise, $truncated, 2))->toBe(1);
});
