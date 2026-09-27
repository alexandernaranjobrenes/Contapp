<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Concerns\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un trabajador. Ver el encabezado de la migración sobre cuáles de sus datos
 * cambian el cálculo y no son meramente administrativos.
 */
class Employee extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Jornada ordinaria máxima por tipo, en horas diarias. De acá sale el
     * umbral a partir del cual una hora es extra (Código de Trabajo art. 136).
     */
    public const ORDINARY_HOURS = [
        'diurna' => 8,
        'mixta' => 7,
        'nocturna' => 6,
    ];

    /**
     * Cuántos pagos de ese tipo caben en un mes. Sirve para llevar cualquier
     * salario a base mensual, que es como se calcula el impuesto.
     */
    public const PERIODS_PER_MONTH = [
        'mensual' => 1,
        'quincenal' => 2,
        'semanal' => 4.3333,
    ];

    /**
     * Entre cuánto se divide el salario de cada modalidad para llegar al
     * valor del DÍA.
     *
     * ── El día es el primitivo, y por eso el divisor importa tanto ───────
     *
     * Vacaciones, aguinaldo, preaviso, cesantía, incapacidades y cualquier
     * pago proporcional se cuentan en días. El valor del día es el número
     * que los multiplica a todos, así que un divisor equivocado se propaga
     * a la liquidación completa.
     *
     * MENSUAL 30 y QUINCENAL 15 porque el salario mensual costarricense cubre
     * los treinta días del mes, incluido el día de descanso semanal, que es
     * pagado.
     *
     * SEMANAL no lleva 7: lleva el divisor de la ficha (6 por defecto, ver la
     * migración que lo agregó). Un semanal de ₡140.000 vale ₡23.333,33 el día
     * con 6 y ₡20.000 con 7 — un 16% de diferencia.
     *
     * DIARIO no se divide: el salario ya ES el día.
     *
     * HORA se MULTIPLICA por las horas de la jornada en vez de dividirse, y
     * por eso no está en esta tabla: se resuelve aparte en dailyRate().
     */
    public const SALARY_TYPE_DAY_DIVISOR = [
        'mensual' => '30',
        'quincenal' => '15',
        'diario' => '1',
    ];

    /** El divisor del semanal cuando la ficha no dice otra cosa. */
    public const DEFAULT_WEEKLY_DIVISOR = 6;

    protected $fillable = [
        'company_id', 'code',
        'identification_type', 'identification_number', 'ccss_number',
        'first_name', 'last_name1', 'last_name2', 'birth_date', 'gender', 'nationality',
        'email', 'phone', 'address', 'photo_path',
        'hire_date', 'termination_date', 'termination_reason',
        'position', 'department', 'department_id', 'job_position_id', 'cost_center_id', 'salary_expense_account_id',
        'contract_type', 'journey_type', 'weekly_hours', 'weekly_salary_divisor',
        'salary_type', 'base_salary',
        'payment_method', 'bank_name', 'bank_account',
        'has_spouse_credit', 'children_credit_count', 'is_income_tax_exempt',
        'is_ccss_exempt', 'is_pensioner', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
            'has_spouse_credit' => 'boolean',
            'is_income_tax_exempt' => 'boolean',
            'is_ccss_exempt' => 'boolean',
            'is_pensioner' => 'boolean',
            // Cadena decimal, no float: toda la aritmética del motor es
            // bcmath y un float en medio reintroduce el error de redondeo.
            'base_salary' => 'decimal:2',
            'weekly_hours' => 'decimal:2',
            'weekly_salary_divisor' => 'integer',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function departmentRecord(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
    }

    /**
     * El departamento y el puesto que se muestran: el del catálogo si lo
     * tiene, el texto escrito a mano si no.
     *
     * Las dos formas conviven a propósito mientras las fichas viejas se
     * mapean al catálogo; sin esto, adoptar el catálogo dejaría en blanco lo
     * que ya estaba escrito.
     */
    public function departmentLabel(): ?string
    {
        return $this->departmentRecord?->name ?? $this->department;
    }

    public function positionLabel(): ?string
    {
        return $this->jobPosition?->name ?? $this->position;
    }

    public function salaryExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'salary_expense_account_id');
    }

    /** Sus boletas. Que existan es lo que impide borrar la ficha. */
    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    public function vacationMovements(): HasMany
    {
        return $this->hasMany(VacationMovement::class);
    }

    public function personnelActions(): HasMany
    {
        return $this->hasMany(PersonnelAction::class);
    }

    /**
     * Saldo de vacaciones: la SUMA de los movimientos, no un campo guardado.
     * Acreditaciones en positivo, disfrutes y pagos en negativo.
     */
    public function vacationBalance(): string
    {
        return $this->vacationMovements()
            ->get(['days'])
            ->reduce(fn ($carry, VacationMovement $m) => bcadd($carry, (string) $m->days, 4), '0.0000');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name1} {$this->last_name2}");
    }

    /**
     * El salario llevado a base MENSUAL, que es la unidad en la que está
     * escrita la escala del impuesto y en la que se reporta a la Caja.
     *
     * Un salario por hora o por día se mensualiza con la jornada declarada;
     * no se asume un mes de 30 días para todos porque un trabajador por
     * horas a medio tiempo daría un número inventado.
     */
    public function monthlySalary(): string
    {
        $salary = (string) $this->base_salary;

        return match ($this->salary_type) {
            'quincenal' => bcmul($salary, '2', 2),
            'semanal' => bcmul($salary, '4.3333', 2),
            'diario' => bcmul($salary, '30', 2),
            'hora' => bcmul($salary, bcmul((string) $this->weekly_hours, '4.3333', 4), 2),
            default => $salary,
        };
    }

    /**
     * Horas ordinarias de la jornada declarada: diurna 8, mixta 7, nocturna 6
     * (CT art. 136). Es el umbral a partir del cual una hora es extra, y el
     * divisor que lleva del día a la hora.
     */
    public function ordinaryHoursPerDay(): string
    {
        return (string) (self::ORDINARY_HOURS[$this->journey_type] ?? 8);
    }

    /**
     * Valor del DÍA ordinario: la unidad primitiva de toda la planilla.
     *
     * ── Cada modalidad tiene su divisor, y no son intercambiables ────────
     *
     * Este método dividía el salario mensualizado entre 30 para TODOS. Para un
     * mensual está bien; para un semanal está mal. Alguien que gana ₡140.000 a
     * la semana se mensualizaba en ₡606.662 (× 4,3333 semanas) y entre 30 daba
     * ₡20.222 el día, cuando su día vale ₡23.333,33 (÷ 6). Un 15% de menos en
     * cada día de vacaciones, aguinaldo, incapacidad y liquidación.
     *
     * La causa del error es que 4,3333 semanas son 26 días laborados, no 30:
     * mensualizar y volver a dividir entre 30 mezcla dos convenciones
     * distintas. Se arregla no pasando por el mes: el día sale directo del
     * salario de la modalidad, con el divisor que le corresponde
     * (ver SALARY_TYPE_DAY_DIVISOR).
     *
     * El caso HORA es el único al revés: el salario ya es la hora, así que el
     * día se obtiene multiplicando por las horas de la jornada. Por eso la
     * hora de un nocturno vale más que la de un diurno con el mismo día: la
     * cumple en seis horas y no en ocho.
     *
     * ── Por qué acepta una escala ───────────────────────────────────────
     *
     * Cuando el día es un paso intermedio —para llegar a la hora— truncarlo a
     * céntimos arrastra el error a cada hora, y siempre hacia abajo. Se pide
     * con más decimales ahí y se redondea al final, sobre el monto de la
     * línea.
     */
    public function dailyRate(int $scale = 2): string
    {
        $salary = (string) $this->base_salary;

        if ($this->salary_type === 'hora') {
            return bcmul($salary, $this->ordinaryHoursPerDay(), $scale);
        }

        $divisor = $this->salary_type === 'semanal'
            ? (string) ($this->weekly_salary_divisor ?: self::DEFAULT_WEEKLY_DIVISOR)
            : (self::SALARY_TYPE_DAY_DIVISOR[$this->salary_type] ?? '30');

        if (bccomp($divisor, '0', 4) <= 0) {
            return bcadd('0', '0', $scale);
        }

        return bcdiv($salary, $divisor, $scale);
    }

    /**
     * Valor de la hora ordinaria: base de las horas extra y del salario
     * devengado cuando se capturan horas.
     *
     * Sale del valor del día entre las horas de la jornada. Para una jornada
     * diurna sobre un salario mensual de ₡400.000: 400.000 ÷ 30 = ₡13.333,33
     * el día, ÷ 8 = ₡1.666,67 la hora.
     *
     * Antes se dividía entre las horas SEMANALES por 4,3333 semanas —208 horas
     * para una semana de 48— y daba ₡1.923,09, un 15% de más en cada hora
     * extra. El error está en que un salario mensual costarricense cubre los
     * 30 días del mes, incluido el día de descanso semanal, que es pagado;
     * dividir entre las horas trabajadas supone que ese día no se paga.
     *
     * Ahora el día es el primitivo y la hora se deriva de él, así que los dos
     * métodos ya no pueden contradecirse: antes uno dividía entre 30 y el otro
     * entre 4,3333 semanas, y no daban lo mismo.
     *
     * El día intermedio se pide con seis decimales por lo dicho en
     * dailyRate(): el valor de la hora es un FACTOR, no un monto que se
     * pague, y truncarlo antes de multiplicarlo por la cantidad de horas le
     * quita al trabajador una fracción en cada una.
     */
    public function hourlyRate(int $scale = 2): string
    {
        $hoursPerDay = $this->ordinaryHoursPerDay();

        if (bccomp($hoursPerDay, '0', 4) <= 0) {
            return bcadd('0', '0', $scale);
        }

        // Un salario por hora ya es la hora: no hay nada que derivar, y pasar
        // por el día y volver solo introduciría redondeo.
        if ($this->salary_type === 'hora') {
            return bcadd((string) $this->base_salary, '0', $scale);
        }

        return bcdiv($this->dailyRate(6), $hoursPerDay, $scale);
    }

    /**
     * Cuántos días paga un mes de salario en esta modalidad.
     *
     * ── Para qué sirve: convertir un promedio histórico en valor del día ──
     *
     * Una liquidación no usa el salario de la ficha: usa el PROMEDIO de lo
     * realmente devengado —últimos 6 meses para preaviso y cesantía (CT art.
     * 30), últimas 50 semanas para vacaciones (art. 157)—. Ese promedio sale
     * de la planilla como un monto mensual, y hay que llevarlo al día.
     *
     * Dividirlo entre 30 para todos es el mismo error que tenía dailyRate():
     * a un trabajador semanal le paga un 15% de menos, porque 4,3333 semanas
     * de trabajo son 26 días pagados y no 30.
     *
     * Así que el factor no se escribe otra vez: se DEDUCE de los dos números
     * que la ficha ya sabe calcular, mes y día. De ese modo no puede
     * divergir de dailyRate() ni cuando cambie el divisor semanal de una
     * ficha en particular.
     *
     * Da 30 para mensual, quincenal y diario; 26 para un semanal con divisor
     * 6; 30,33 con divisor 7; y 26 para un salario por hora de jornada
     * completa.
     */
    public function paidDaysPerMonth(int $scale = 4): string
    {
        $daily = $this->dailyRate(6);

        if (bccomp($daily, '0', 6) <= 0) {
            return bcadd('30', '0', $scale);
        }

        return bcdiv($this->monthlySalary(), $daily, $scale);
    }

    /**
     * Años completos de servicio a una fecha. De esto dependen el monto de
     * cesantía (art. 29) y el preaviso (art. 28).
     */
    public function yearsOfService(\DateTimeInterface $at): int
    {
        return (int) $this->hire_date->diffInYears(Carbon::parse($at));
    }

    /**
     * Si estaba contratado durante el período. Un trabajador que ingresó a
     * mitad de quincena o que salió antes NO se excluye: se le paga lo
     * proporcional, y eso lo resuelve el motor con los días trabajados.
     */
    public function wasEmployedDuring(string $from, string $to): bool
    {
        if ($this->hire_date->format('Y-m-d') > $to) {
            return false;
        }

        return $this->termination_date === null
            || $this->termination_date->format('Y-m-d') >= $from;
    }
}
