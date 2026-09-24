<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    employees: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
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
    cost_center_id: '',
    salary_expense_account_id: '',
    contract_type: 'indefinido',
    journey_type: 'diurna',
    weekly_hours: 48,
    salary_type: 'mensual',
    base_salary: '',
    payment_method: 'transferencia',
    bank_name: '',
    bank_account: '',
    has_spouse_credit: false,
    children_credit_count: 0,
    is_income_tax_exempt: false,
    is_ccss_exempt: false,
    status: 'active',
    notes: '',
};

const creating = ref(false);
const editing = ref(null);
const createForm = useForm({ ...blank });
const editForm = useForm({ ...blank });
const activeForm = computed(() => (creating.value ? createForm : editForm));

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

function openCreate() {
    createForm.reset();
    creating.value = true;
}

function openEdit(employee) {
    editForm.clearErrors();

    Object.keys(blank).forEach((key) => {
        editForm[key] = employee[key] ?? blank[key];
    });

    editing.value = employee;
}

// Un select vacío manda '' y el validador lo tomaría como un id inválido en
// vez de "sin asignar".
function normalize(data) {
    const blanks = [
        'birth_date', 'termination_date', 'termination_reason', 'cost_center_id',
        'salary_expense_account_id', 'gender', 'nationality', 'email', 'phone',
        'address', 'ccss_number', 'bank_name', 'bank_account', 'position', 'department', 'notes',
    ];

    const out = { ...data };
    blanks.forEach((key) => { if (out[key] === '') out[key] = null; });

    return out;
}

function submitCreate() {
    createForm.transform(normalize).post(route('employees.store'), {
        onSuccess: () => (creating.value = false), preserveScroll: true,
    });
}

function submitEdit() {
    editForm.transform(normalize).put(route('employees.update', editing.value.id), {
        onSuccess: () => (editing.value = null), preserveScroll: true,
    });
}

function destroy(employee) {
    if (! confirm(`¿Eliminar la ficha de ${employee.code} — ${employee.full_name}?`)) return;

    router.delete(route('employees.destroy', employee.id), { preserveScroll: true });
}

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
        <template #actions>
            <Link :href="route('payroll-periods.index')" class="btn btn-ghost">Períodos de planilla</Link>
            <Link :href="route('payroll-settings.index')" class="btn btn-ghost">Configuración</Link>
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

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
            <div class="card-header">
                <input v-model="search" type="search" placeholder="Buscar por código, nombre, cédula o puesto" class="search-input">
                <label class="check inline">
                    <input v-model="showTerminated" type="checkbox">
                    Mostrar dados de baja
                </label>
                <span class="muted">{{ visible.length }} de {{ employees.length }}</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nuevo empleado</button>
            </div>

            <div class="table-scroll freeze-2">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Identificación</th>
                            <th>Puesto</th>
                            <th>Centro de costo</th>
                            <th>Ingreso</th>
                            <th>Jornada</th>
                            <th class="right">Salario base</th>
                            <th>Pago</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in visible" :key="e.id">
                            <td class="num">{{ e.code }}</td>
                            <td>
                                <Link :href="route('employees.show', e.id)">{{ e.full_name }}</Link>
                            </td>
                            <td class="num small">{{ e.identification_number }}</td>
                            <td>{{ e.position ?? '—' }}</td>
                            <td class="muted small">{{ e.cost_center ?? '—' }}</td>
                            <td class="num small">{{ e.hire_date }}</td>
                            <td class="muted small">
                                {{ options.journeyTypes[e.journey_type] ?? e.journey_type }}
                            </td>
                            <td class="right">{{ formatMoney(e.base_salary) }}</td>
                            <td class="muted small">
                                {{ options.paymentMethods[e.payment_method] }}
                                <span v-if="e.payment_method === 'transferencia' && ! e.bank_account" class="warn-dot" title="Sin cuenta bancaria: no va a entrar al archivo de pago">⚠</span>
                            </td>
                            <td>{{ options.statuses[e.status] ?? e.status }}</td>
                            <td class="row-actions">
                                <Link :href="route('employees.show', e.id)" class="btn btn-ghost btn-sm">Ficha</Link>
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEdit(e)">Editar</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="destroy(e)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="11" class="muted empty-row">
                                {{ employees.length ? 'Ningún empleado coincide con la búsqueda.' : 'Todavía no hay empleados registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating || editing" class="modal-backdrop" @click.self="creating = false; editing = null">
            <form class="modal modal-wide card" @submit.prevent="creating ? submitCreate() : submitEdit()">
                <h2>{{ creating ? 'Nuevo empleado' : `Editar ${editing.code} — ${editing.full_name}` }}</h2>

                <h3 class="section-title">Identidad</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Código</label>
                        <input v-model="activeForm.code" type="text" maxlength="20" required>
                        <span v-if="activeForm.errors.code" class="error">{{ activeForm.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label>Tipo de identificación</label>
                        <select v-model="activeForm.identification_type" required>
                            <option v-for="(label, value) in options.identificationTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Número</label>
                        <input v-model="activeForm.identification_number" type="text" maxlength="30" required>
                        <span v-if="activeForm.errors.identification_number" class="error">{{ activeForm.errors.identification_number }}</span>
                    </div>
                    <div class="field">
                        <label>Asegurado CCSS</label>
                        <input v-model="activeForm.ccss_number" type="text" maxlength="30">
                        <span class="hint small">Obligatorio en la planilla de la Caja.</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="activeForm.first_name" type="text" required>
                    </div>
                    <div class="field">
                        <label>Primer apellido</label>
                        <input v-model="activeForm.last_name1" type="text" required>
                    </div>
                    <div class="field">
                        <label>Segundo apellido</label>
                        <input v-model="activeForm.last_name2" type="text">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Fecha de nacimiento</label>
                        <input v-model="activeForm.birth_date" type="date">
                    </div>
                    <div class="field">
                        <label>Correo</label>
                        <input v-model="activeForm.email" type="email">
                    </div>
                    <div class="field">
                        <label>Teléfono</label>
                        <input v-model="activeForm.phone" type="text" maxlength="30">
                    </div>
                </div>

                <h3 class="section-title">Relación laboral</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Fecha de ingreso</label>
                        <input v-model="activeForm.hire_date" type="date" required>
                        <span class="hint small">De acá dependen antigüedad, vacaciones y cesantía.</span>
                        <span v-if="activeForm.errors.hire_date" class="error">{{ activeForm.errors.hire_date }}</span>
                    </div>
                    <div class="field">
                        <label>Puesto</label>
                        <input v-model="activeForm.position" type="text">
                    </div>
                    <div class="field">
                        <label>Departamento</label>
                        <input v-model="activeForm.department" type="text">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Centro de costo</label>
                        <select v-model="activeForm.cost_center_id">
                            <option value="">Sin centro de costo</option>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <span class="hint small">Lleva su gasto al área que lo consume, sin repartirlo a mano.</span>
                    </div>
                    <div class="field">
                        <label>Cuenta de gasto (opcional)</label>
                        <select v-model="activeForm.salary_expense_account_id">
                            <option value="">Heredar de la configuración de planilla</option>
                            <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                        <span class="hint small">
                            Para separar mano de obra directa de gasto administrativo. Vacío hereda.
                        </span>
                    </div>
                    <div class="field">
                        <label>Tipo de contrato</label>
                        <select v-model="activeForm.contract_type" required>
                            <option v-for="(label, value) in options.contractTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Jornada</label>
                        <select v-model="activeForm.journey_type" required>
                            <option v-for="(label, value) in options.journeyTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <span class="hint small">Fija el umbral a partir del cual una hora es extra.</span>
                    </div>
                    <div class="field">
                        <label>Horas semanales</label>
                        <input v-model="activeForm.weekly_hours" type="number" step="0.01" min="0.01" max="168" required>
                    </div>
                </div>

                <h3 class="section-title">Remuneración y pago</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Tipo de salario</label>
                        <select v-model="activeForm.salary_type" required>
                            <option v-for="(label, value) in options.salaryTypes" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Salario base</label>
                        <input v-model="activeForm.base_salary" type="number" step="0.01" min="0" required>
                        <span class="hint small">En la unidad del tipo de salario elegido.</span>
                        <span v-if="activeForm.errors.base_salary" class="error">{{ activeForm.errors.base_salary }}</span>
                    </div>
                    <div class="field">
                        <label>Forma de pago</label>
                        <select v-model="activeForm.payment_method" required>
                            <option v-for="(label, value) in options.paymentMethods" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="activeForm.payment_method === 'transferencia'" class="field-row">
                    <div class="field">
                        <label>Banco</label>
                        <input v-model="activeForm.bank_name" type="text">
                    </div>
                    <div class="field">
                        <label>Cuenta IBAN</label>
                        <input v-model="activeForm.bank_account" type="text" maxlength="34">
                        <span class="hint small">Sin esto no entra al archivo de pago y hay que transferirle a mano.</span>
                    </div>
                </div>

                <h3 class="section-title">Impuesto y cargas sociales</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Hijos con crédito</label>
                        <input v-model="activeForm.children_credit_count" type="number" min="0" max="30">
                    </div>
                    <div class="field">
                        <label class="check">
                            <input v-model="activeForm.has_spouse_credit" type="checkbox">
                            Crédito por cónyuge
                        </label>
                        <span class="hint small">Los créditos se restan del impuesto, no de la base.</span>
                    </div>
                </div>

                <label class="check">
                    <input v-model="activeForm.is_income_tax_exempt" type="checkbox">
                    No aplicar impuesto al salario
                </label>

                <label class="check">
                    <input v-model="activeForm.is_ccss_exempt" type="checkbox">
                    No cotiza cargas sociales por esta planilla
                </label>
                <span class="hint small">
                    Las dos son excepciones y tienen que poder sustentarse. Marcarlas por error deja de rebajar
                    lo que la ley manda rebajar.
                </span>

                <h3 class="section-title">Estado</h3>

                <div class="field-row">
                    <div class="field">
                        <label>Estado</label>
                        <select v-model="activeForm.status" required>
                            <option v-for="(label, value) in options.statuses" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div v-if="activeForm.status === 'terminated'" class="field">
                        <label>Fecha de salida</label>
                        <input v-model="activeForm.termination_date" type="date">
                        <span v-if="activeForm.errors.termination_date" class="error">{{ activeForm.errors.termination_date }}</span>
                    </div>
                    <div v-if="activeForm.status === 'terminated'" class="field">
                        <label>Motivo</label>
                        <select v-model="activeForm.termination_reason">
                            <option value="">Sin indicar</option>
                            <option v-for="(label, value) in options.terminationReasons" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <span class="hint small">Decide si corresponden preaviso y cesantía.</span>
                    </div>
                </div>

                <div class="field">
                    <label>Notas</label>
                    <textarea v-model="activeForm.notes" rows="2"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="creating = false; editing = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="activeForm.processing">Guardar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.stat-row {
    display: flex;
    flex-wrap: wrap;
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
    min-width: 12rem;
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

.search-input {
    min-width: 20rem;
    flex: 1;
}

.check.inline {
    margin: 0;
    white-space: nowrap;
}

.warn-dot {
    color: #b45309;
    cursor: help;
}
</style>
