<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';
import { formatMoney } from '../../../Utils/money';
import { PencilIcon, PlusIcon, TriangleAlertIcon, UserIcon } from '@lucide/vue';

const props = defineProps({
    employees: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
    departmentOptions: { type: Array, default: () => [] },
    positionOptions: { type: Array, default: () => [] },
    expenseAccounts: { type: Array, default: () => [] },
    options: { type: Object, required: true },
});

const page = usePage();

const blank = {
    code: '',
    identification_type: 'cedula',
    identification_number: '',
    ccss_number: '',
    first_name: '',
    last_name1: '',
    last_name2: '',
    birth_date: '',
    gender: '',
    nationality: '',
    email: '',
    phone: '',
    address: '',
    hire_date: '',
    termination_date: '',
    termination_reason: '',
    position: '',
    department: '',
    department_id: '',
    job_position_id: '',
    cost_center_id: '',
    salary_expense_account_id: '',
    contract_type: 'indefinido',
    journey_type: 'diurna',
    weekly_hours: 48,
    weekly_salary_divisor: 6,
    salary_type: 'mensual',
    base_salary: '',
    payment_method: 'transferencia',
    bank_name: '',
    bank_account: '',
    has_spouse_credit: false,
    children_credit_count: 0,
    is_income_tax_exempt: false,
    is_ccss_exempt: false,
    is_pensioner: false,
    status: 'active',
    notes: '',
};

const search = ref('');
const showTerminated = ref(false);

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return props.employees.filter((e) => {
        if (! showTerminated.value && e.status === 'terminated') return false;
        if (! needle) return true;

        return [e.code, e.full_name, e.identification_number, e.position, e.department]
            .filter(Boolean)
            .some((v) => String(v).toLowerCase().includes(needle));
    });
});

// Un select vacío manda '' y el validador lo tomaría como un id inválido en
// vez de "sin asignar".
function normalize(data) {
    const blanks = [
        'birth_date', 'termination_date', 'termination_reason', 'cost_center_id',
        'salary_expense_account_id', 'gender', 'nationality', 'email', 'phone',
        'address', 'ccss_number', 'bank_name', 'bank_account', 'position', 'department',
        'department_id', 'job_position_id', 'notes',
    ];

    const out = { ...data };
    blanks.forEach((key) => { if (out[key] === '') out[key] = null; });

    return out;
}

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21); la
// ficha completa del trabajador, con su historial, es su propia pantalla.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.employees,
    defaults: () => ({ ...blank }),
    toForm: (e) => Object.fromEntries(Object.keys(blank).map((key) => [key, e[key] ?? blank[key]])),
    store: () => route('employees.store'),
    update: (e) => route('employees.update', e.id),
    storePayload: normalize,
    updatePayload: normalize,
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo empleado';
    return selected.value ? `${selected.value.code} — ${selected.value.full_name}` : '';
});

function destroy() {
    const employee = selected.value;

    confirmAction({
        title: 'Eliminar ficha',
        message: `La ficha de ${employee.code} — ${employee.full_name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('employees.destroy', employee.id), { preserveScroll: true }),
    });
}

// Los puestos del departamento elegido: ofrecer los de toda la empresa haría
// que en una lista de cien puestos nadie encuentre el suyo.
const positionsForDepartment = computed(() => {
    const departmentId = Number(form.department_id);

    if (! departmentId) return props.positionOptions;

    return props.positionOptions.filter((p) => ! p.department_id || p.department_id === departmentId);
});

// Al elegir departamento se propone su centro de costo, para no tener que
// acordarse de ponérselo a cada ficha. Solo si todavía no hay uno puesto:
// pisarlo cambiaría a dónde va el gasto de alguien sin avisar.
function applyDepartmentDefaults() {
    const department = props.departmentOptions.find((d) => d.id === Number(form.department_id));

    if (department?.cost_center_id && ! form.cost_center_id) {
        form.cost_center_id = department.cost_center_id;
    }

    // Si el puesto elegido ya no pertenece al departamento nuevo, se suelta.
    const stillValid = positionsForDepartment.value.some((p) => p.id === Number(form.job_position_id));

    if (! stillValid) form.job_position_id = '';
}

// Un salario fuera del rango del puesto no bloquea nada: avisa. Un cero de
// más en un aumento no lo detecta nadie leyendo la ficha.
const salaryOutOfRange = computed(() => {
    const position = props.positionOptions.find((p) => p.id === Number(form.job_position_id));
    const salary = parseFloat(form.base_salary);

    if (! position || ! salary) return null;

    const min = position.min_salary === null ? null : parseFloat(position.min_salary);
    const max = position.max_salary === null ? null : parseFloat(position.max_salary);

    if (min !== null && salary < min) return `por debajo del mínimo del puesto (${formatMoney(min)})`;
    if (max !== null && salary > max) return `por encima del máximo del puesto (${formatMoney(max)})`;

    return null;
});

const activeCount = computed(() => props.employees.filter((e) => e.status === 'active').length);

// Lo que la planilla va a costar por mes si nada cambia. No es el salario
// bruto: es lo único que sirve para presupuestar, y por eso va arriba.
const monthlyBase = computed(() => props.employees
    .filter((e) => e.status === 'active')
    .reduce((sum, e) => sum + (parseFloat(e.base_salary) || 0), 0));
</script>

<template>
    <Head title="Empleados" />

    <AppLayout title="Empleados">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('payroll-periods.index')" class="btn btn-ghost">Períodos de planilla</Link>
                <Link :href="route('payroll-settings.index')" class="btn btn-ghost">Configuración</Link>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar por código, nombre, cédula o puesto" aria-label="Buscar empleado">
                <label class="check">
                    <input v-model="showTerminated" type="checkbox">
                    Mostrar dados de baja
                </label>
                <span class="muted small">{{ visible.length }} de {{ employees.length }}</span>
            </div>
        </div>

        <div v-if="page.props.errors?.employee" class="flash flash-error">{{ page.props.errors.employee }}</div>

        <p class="hint">
            La ficha decide más de lo que parece: la <strong>jornada</strong> fija a partir de qué hora una hora
            es extra (diurna 8, mixta 7, nocturna 6), el <strong>tipo de salario</strong> decide cómo se deriva el
            valor de la hora y del día, y el <strong>centro de costo</strong> es lo que lleva el gasto de cada
            trabajador a donde corresponde sin repartirlo a mano.
        </p>

        <div class="stat-row">
            <div class="stat">
                <span class="stat-label">Activos</span>
                <span class="stat-value">{{ activeCount }}</span>
            </div>
            <div class="stat">
                <span class="stat-label">Salarios base mensuales</span>
                <span class="stat-value">{{ formatMoney(monthlyBase) }}</span>
                <span class="stat-note">sin cargas patronales ni provisiones</span>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Puesto</th>
                            <th class="num">Salario base</th>
                            <th>Pago</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in visible"
                            :key="e.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(e)"
                            @keydown.enter="openDetail(e)"
                            @keydown.space.prevent="openDetail(e)"
                        >
                            <td><span class="code">{{ e.code }}</span> — {{ e.full_name }}</td>
                            <td data-label="Puesto">{{ e.position ?? '—' }}</td>
                            <td data-label="Salario base" class="num">
                                {{ formatMoney(e.base_salary) }}
                                <!--
                                    El día y la hora derivados, en letra chica bajo el
                                    salario: son el primitivo con el que se paga todo lo
                                    demás, y verlos aquí delata un divisor mal escogido
                                    antes de que la planilla se calcule con él.
                                -->
                                <span class="muted small rate-hint">
                                    día {{ formatMoney(e.day_rate) }} · hora {{ formatMoney(e.hour_rate) }}
                                </span>
                            </td>
                            <td data-label="Pago" class="muted small">
                                {{ options.paymentMethods[e.payment_method] }}
                                <span v-if="e.payment_method === 'transferencia' && ! e.bank_account" class="warn-dot" title="Sin cuenta bancaria: no va a entrar al archivo de pago"><TriangleAlertIcon aria-label="Sin cuenta bancaria" role="img" /></span>
                            </td>
                            <td data-label="Estado">
                                <span class="badge" :class="e.status === 'active' ? 'badge-success' : 'badge-neutral'">{{ options.statuses[e.status] ?? e.status }}</span>
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="5" class="muted empty-row">
                                {{ employees.length ? 'Ningún empleado coincide con la búsqueda.' : 'Todavía no hay empleados registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" wide :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-neutral'">
                    {{ options.statuses[selected.status] ?? selected.status }}
                </span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Identificación</dt>
                    <dd>{{ selected.identification_number }}</dd>
                </div>
                <div>
                    <dt>Puesto</dt>
                    <dd>{{ selected.position ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Departamento</dt>
                    <dd>{{ selected.department ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Centro de costo</dt>
                    <dd>{{ selected.cost_center ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Ingreso</dt>
                    <dd>{{ selected.hire_date }}</dd>
                </div>
                <div>
                    <dt>Jornada</dt>
                    <dd>{{ options.journeyTypes[selected.journey_type] ?? selected.journey_type }}</dd>
                </div>
                <div>
                    <dt>Salario base</dt>
                    <dd>
                        {{ formatMoney(selected.base_salary) }}
                        <span class="muted small">(día {{ formatMoney(selected.day_rate) }} · hora {{ formatMoney(selected.hour_rate) }})</span>
                    </dd>
                </div>
                <div>
                    <dt>Forma de pago</dt>
                    <dd>
                        {{ options.paymentMethods[selected.payment_method] }}
                        <span v-if="selected.payment_method === 'transferencia' && ! selected.bank_account" class="warn-text">
                            — sin cuenta bancaria: no va a entrar al archivo de pago
                        </span>
                    </dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="employee-form" @submit.prevent="submit">
                <h3 class="section-title">Identidad</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Código</label>
                        <input v-model="form.code" type="text" maxlength="20" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label>Tipo de identificación</label>
                        <select v-model="form.identification_type" required>
                            <option v-for="(label, value) in options.identificationTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Número</label>
                        <input v-model="form.identification_number" type="text" maxlength="30" required>
                        <span v-if="form.errors.identification_number" class="error">{{ form.errors.identification_number }}</span>
                    </div>
                    <div class="field">
                        <label>Asegurado CCSS</label>
                        <input v-model="form.ccss_number" type="text" maxlength="30">
                        <span class="muted small">Obligatorio en la planilla de la Caja.</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="form.first_name" type="text" required>
                    </div>
                    <div class="field">
                        <label>Primer apellido</label>
                        <input v-model="form.last_name1" type="text" required>
                    </div>
                    <div class="field">
                        <label>Segundo apellido</label>
                        <input v-model="form.last_name2" type="text">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Fecha de nacimiento</label>
                        <input v-model="form.birth_date" type="date">
                    </div>
                    <div class="field">
                        <label>Correo</label>
                        <input v-model="form.email" type="email">
                    </div>
                    <div class="field">
                        <label>Teléfono</label>
                        <input v-model="form.phone" type="text" maxlength="30">
                    </div>
                </div>

                <h3 class="section-title">Relación laboral</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Fecha de ingreso</label>
                        <input v-model="form.hire_date" type="date" required>
                        <span class="muted small">De acá dependen antigüedad, vacaciones y cesantía.</span>
                        <span v-if="form.errors.hire_date" class="error">{{ form.errors.hire_date }}</span>
                    </div>
                    <div class="field">
                        <label>Departamento</label>
                        <select v-model="form.department_id" @change="applyDepartmentDefaults">
                            <option value="">Sin departamento</option>
                            <option v-for="d in departmentOptions" :key="d.id" :value="d.id">{{ d.code }} — {{ d.name }}</option>
                        </select>
                        <span v-if="!departmentOptions.length" class="hint small">
                            Todavía no hay departamentos en el catálogo.
                        </span>
                    </div>
                    <div class="field">
                        <label>Puesto</label>
                        <select v-model="form.job_position_id">
                            <option value="">Sin puesto</option>
                            <option v-for="p in positionsForDepartment" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                        </select>
                        <span class="muted small">El código de ocupación de la CCSS sale del puesto.</span>
                    </div>
                </div>

                <p v-if="salaryOutOfRange" class="flash flash-warning">
                    El salario está {{ salaryOutOfRange }}. No bloquea nada — hay excepciones legítimas — pero
                    conviene mirarlo: un cero de más en un aumento no se detecta leyendo la ficha.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label>Centro de costo</label>
                        <select v-model="form.cost_center_id">
                            <option value="">Sin centro de costo</option>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <span class="muted small">Lleva su gasto al área que lo consume, sin repartirlo a mano.</span>
                    </div>
                    <div class="field">
                        <label>Cuenta de gasto (opcional)</label>
                        <select v-model="form.salary_expense_account_id">
                            <option value="">Heredar de la configuración de planilla</option>
                            <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                        <span class="muted small">
                            Para separar mano de obra directa de gasto administrativo. Vacío hereda.
                        </span>
                    </div>
                    <div class="field">
                        <label>Tipo de contrato</label>
                        <select v-model="form.contract_type" required>
                            <option v-for="(label, value) in options.contractTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Jornada</label>
                        <select v-model="form.journey_type" required>
                            <option v-for="(label, value) in options.journeyTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <span class="muted small">Fija el umbral a partir del cual una hora es extra.</span>
                    </div>
                    <div class="field">
                        <label>Horas semanales</label>
                        <input v-model="form.weekly_hours" type="number" step="0.01" min="0.01" max="168" required>
                    </div>
                </div>

                <h3 class="section-title">Remuneración y pago</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Tipo de salario</label>
                        <select v-model="form.salary_type" required>
                            <option v-for="(label, value) in options.salaryTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Salario base</label>
                        <input v-model="form.base_salary" type="number" step="0.01" min="0" required>
                        <span class="muted small">En la unidad del tipo de salario elegido.</span>
                        <span v-if="form.errors.base_salary" class="error">{{ form.errors.base_salary }}</span>
                    </div>
                    <!--
                        El divisor del día solo existe para el salario semanal:
                        en las demás modalidades lo fija la ley (30 el mes, 15
                        la quincena) y preguntarlo sería ofrecer un error.
                    -->
                    <div v-if="form.salary_type === 'semanal'" class="field">
                        <label>Divisor del día</label>
                        <select v-model.number="form.weekly_salary_divisor" required>
                            <option :value="6">6 — la semana paga los días laborados</option>
                            <option :value="7">7 — la semana incluye el descanso</option>
                        </select>
                        <span class="muted small">
                            Entre 6 y 7 hay un 16% de diferencia en el valor del día, y es el
                            número que multiplica los días de vacaciones, aguinaldo y liquidación.
                        </span>
                        <span v-if="form.errors.weekly_salary_divisor" class="error">{{ form.errors.weekly_salary_divisor }}</span>
                    </div>
                    <div class="field">
                        <label>Forma de pago</label>
                        <select v-model="form.payment_method" required>
                            <option v-for="(label, value) in options.paymentMethods" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="form.payment_method === 'transferencia'" class="field-row">
                    <div class="field">
                        <label>Banco</label>
                        <input v-model="form.bank_name" type="text">
                    </div>
                    <div class="field">
                        <label>Cuenta IBAN</label>
                        <input v-model="form.bank_account" type="text" maxlength="34">
                        <span class="muted small">Sin esto no entra al archivo de pago y hay que transferirle a mano.</span>
                    </div>
                </div>

                <h3 class="section-title">Impuesto y cargas sociales</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Hijos con crédito</label>
                        <input v-model="form.children_credit_count" type="number" min="0" max="30">
                    </div>
                    <div class="field">
                        <label class="check">
                            <input v-model="form.has_spouse_credit" type="checkbox">
                            Crédito por cónyuge
                        </label>
                        <span class="muted small">Los créditos se restan del impuesto, no de la base.</span>
                    </div>
                </div>

                <label class="check">
                    <input v-model="form.is_income_tax_exempt" type="checkbox">
                    No aplicar impuesto al salario
                </label>

                <label class="check">
                    <input v-model="form.is_pensioner" type="checkbox">
                    Pensionado
                </label>
                <span class="muted small">
                    Un pensionado <strong>sí cotiza</strong> Enfermedad y Maternidad y Banco Popular —el 6,50%—
                    pero no IVM, ni él ni el patrono, porque ya está pensionado por ese régimen. No es lo mismo
                    que la exención total de abajo: usar esa le quitaría también el 6,50% que sí debe, y la
                    empresa quedaría debiéndoselo a la Caja.
                </span>

                <label class="check">
                    <input v-model="form.is_ccss_exempt" type="checkbox">
                    No cotiza NINGUNA carga social por esta planilla
                </label>
                <span class="muted small">
                    Las tres son excepciones y tienen que poder sustentarse. Marcarlas por error deja de rebajar
                    lo que la ley manda rebajar.
                </span>

                <h3 class="section-title">Estado</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Estado</label>
                        <select v-model="form.status" required>
                            <option v-for="(label, value) in options.statuses" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div v-if="form.status === 'terminated'" class="field">
                        <label>Fecha de salida</label>
                        <input v-model="form.termination_date" type="date">
                        <span v-if="form.errors.termination_date" class="error">{{ form.errors.termination_date }}</span>
                    </div>
                    <div v-if="form.status === 'terminated'" class="field">
                        <label>Motivo</label>
                        <select v-model="form.termination_reason">
                            <option value="">Sin indicar</option>
                            <option v-for="(label, value) in options.terminationReasons" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <span class="muted small">Decide si corresponden preaviso y cesantía.</span>
                    </div>
                </div>

                <div class="field">
                    <label>Notas</label>
                    <textarea v-model="form.notes" rows="2"></textarea>
                </div>

            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link :href="route('employees.show', selected.id)" class="btn btn-ghost"><UserIcon /> Ver ficha completa</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="employee-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.stat-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.stat {
    display: flex;
    flex-direction: column;
    padding: 0.75rem 1.25rem;
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    background: var(--color-surface);
}

.stat-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-muted);
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.stat-note {
    font-size: 0.7rem;
    color: var(--color-text-muted);
}

table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }

.warn-dot {
    color: var(--color-warning);
    cursor: help;
}

.warn-text { color: var(--color-warning); font-size: 0.8rem; }

.rate-hint {
    display: block;
    font-variant-numeric: tabular-nums;
}
</style>
