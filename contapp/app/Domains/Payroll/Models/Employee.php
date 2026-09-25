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

    protected $fillable = [
        'company_id', 'code',
        'identification_type', 'identification_number', 'ccss_number',
        'first_name', 'last_name1', 'last_name2', 'birth_date', 'gender', 'nationality',
        'email', 'phone', 'address', 'photo_path',
        'hire_date', 'termination_date', 'termination_reason',
        'position', 'department', 'cost_center_id', 'salary_expense_account_id',
        'contract_type', 'journey_type', 'weekly_hours',
        'salary_type', 'base_salary',
        'payment_method', 'bank_name', 'bank_account',
        'has_spouse_credit', 'children_credit_count', 'is_income_tax_exempt',
        'is_ccss_exempt', 'status', 'notes',
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
            // Cadena decimal, no float: toda la aritmética del motor es
            // bcmath y un float en medio reintroduce el error de redondeo.
            'base_salary' => 'decimal:2',
            'weekly_hours' => 'decimal:2',
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
     * Valor de la hora ordinaria: base de las horas extra.
     *
     * ── El divisor es el DÍA, no la semana ───────────────────────────────
     *
     * Se deriva del valor del día —salario mensual entre 30— dividido entre
     * las horas ordinarias de la jornada. Para una jornada diurna sobre un
     * salario de ₡400.000: 400.000 ÷ 30 = ₡13.333,33 el día, ÷ 8 = ₡1.666,67
     * la hora.
     *
     * Antes se dividía entre las horas SEMANALES por 4,3333 semanas, o sea
     * 208 horas para una semana de 48, y daba ₡1.923,09 — un 15% de más en
     * cada hora extra. El error está en que un salario mensual costarricense
     * cubre los 30 días del mes, incluido el día de descanso semanal, que es
     * pagado; dividir entre las horas trabajadas supone que ese día no se
     * paga.
     *
     * Además dejaba a `dailyRate()` contradiciendo a este método: uno dividía
     * entre 30 y el otro entre 4,3333 semanas, y no daban lo mismo.
     *
     * La jornada declarada es la que fija las horas del día —diurna 8, mixta
     * 7, nocturna 6 (CT art. 136)— y por eso la hora de un trabajador
     * nocturno vale más: cumple su jornada en menos horas por el mismo día.
     *
     * ── Por qué el cálculo pide más decimales que la pantalla ────────────
     *
     * El valor de la hora es un FACTOR, no un monto que se pague. Truncarlo
     * a céntimos antes de multiplicarlo por la cantidad de horas pierde una
     * fracción en cada hora, y siempre hacia abajo: cuarenta horas extra al
     * mes se convierten en varios colones que el trabajador ganó y no
     * recibe. El redondeo va al final, sobre el monto de la línea.
     *
     * Por eso el motor lo pide con cuatro decimales y la pantalla con dos.
     */
    public function hourlyRate(int $scale = 2): string
    {
        $hoursPerDay = (string) (self::ORDINARY_HOURS[$this->journey_type] ?? 8);

        if (bccomp($hoursPerDay, '0', 4) <= 0) {
            return '0.00';
        }

        // El día se calcula con más precisión de la que se muestra: es un
        // paso intermedio y truncarlo acá arrastraría el error a cada hora.
        return bcdiv(bcdiv($this->monthlySalary(), '30', 6), $hoursPerDay, $scale);
    }

    /**
     * Valor del día ordinario: base de vacaciones y aguinaldo.
     */
    public function dailyRate(): string
    {
        return bcdiv($this->monthlySalary(), '30', 2);
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
