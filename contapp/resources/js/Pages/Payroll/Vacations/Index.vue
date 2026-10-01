<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { formatMoney } from '../../../Utils/money';
import { PlusIcon, UserIcon, UsersIcon } from '@lucide/vue';

const props = defineProps({
    employees: { type: Array, default: () => [] },
    movements: { type: Array, default: () => [] },
    types: { type: Object, required: true },
});

const page = usePage();

const creating = ref(false);
const form = useForm({
    employee_id: '',
    type: 'taken',
    movement_date: '',
    days: '',
    from_date: '',
    to_date: '',
    amount: '',
    notes: '',
});

function openCreate(employeeId = '') {
    form.reset();
    form.employee_id = employeeId;
    creating.value = true;
}

// Al indicar el rango, los días se cuentan solos. Es lo que la gente hace a
// mano y donde se equivoca (olvidar que ambos extremos cuentan).
function syncDays() {
    if (! form.from_date || ! form.to_date) return;

    const from = new Date(`${form.from_date}T00:00:00`);
    const to = new Date(`${form.to_date}T00:00:00`);

    if (to < from) return;

    form.days = Math.round((to - from) / 86400000) + 1;

    if (! form.movement_date) form.movement_date = form.from_date;
}

function submit() {
    form.transform((data) => ({
        ...data,
        from_date: data.from_date === '' ? null : data.from_date,
        to_date: data.to_date === '' ? null : data.to_date,
        amount: data.amount === '' ? null : data.amount,
        notes: data.notes === '' ? null : data.notes,
    })).post(route('vacations.store'), {
        onSuccess: () => (creating.value = false), preserveScroll: true,
    });
}

// Fichas (CLAUDE.md secc. 20): la del trabajador, con su saldo y el botón
// para registrarle un movimiento, y la del movimiento, con su detalle y
// «Eliminar».
const { selected: selectedEmployee, openDetail: openEmployee, closeDetail: closeEmployee } = useRecordDetail(() => props.employees);
const { selected: selectedMovement, openDetail: openMovement, closeDetail: closeMovement } = useRecordDetail(() => props.movements);

function registerFor(employee) {
    closeEmployee();
    openCreate(employee.id);
}

function destroy() {
    const movement = selectedMovement.value;

    confirmAction({
        title: 'Eliminar movimiento',
        message: `El movimiento de ${movement.employee_name} del ${movement.movement_date} se elimina y el saldo se recalcula.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('vacations.destroy', movement.id), { preserveScroll: true }),
    });
}

const search = ref('');

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (! needle) return props.employees;

    return props.employees.filter((e) => `${e.code} ${e.name} ${e.position ?? ''}`.toLowerCase().includes(needle));
});

const totalDays = computed(() => props.employees.reduce((sum, e) => sum + e.balance, 0));

// Lo que costaría pagar hoy todo lo acumulado. Las vacaciones sin disfrutar
// son un pasivo real y esta es la única pantalla donde se ve completo.
const totalLiability = computed(
    () => props.employees.reduce((sum, e) => sum + e.balance * (parseFloat(e.daily_rate) || 0), 0)
);

const negative = computed(() => props.employees.filter((e) => e.balance < 0));

// ── Proceso masivo ──────────────────────────────────────────────────────
//
// El cierre de fin de año, los saldos iniciales, una acreditación por
// convenio. Hacerlo uno por uno con cincuenta personas no es solo lento: es
// donde se salta a alguien, y ese alguien se entera un año después.

const selected = ref([]);
const bulking = ref(false);

const bulkForm = useForm({
    employee_ids: [],
    type: 'taken',
    movement_date: '',
    days: '',
    from_date: '',
    to_date: '',
    notes: '',
});

const allVisibleSelected = computed(
    () => visible.value.length > 0 && visible.value.every((e) => selected.value.includes(e.id))
);

function toggleAll() {
    const ids = visible.value.map((e) => e.id);

    selected.value = allVisibleSelected.value
        ? selected.value.filter((id) => ! ids.includes(id))
        : [...new Set([...selected.value, ...ids])];
}

function openBulk() {
    bulkForm.reset();
    bulkForm.clearErrors();
    bulking.value = true;
}

function syncBulkDays() {
    if (! bulkForm.from_date || ! bulkForm.to_date) return;

    const from = new Date(`${bulkForm.from_date}T00:00:00`);
    const to = new Date(`${bulkForm.to_date}T00:00:00`);

    if (to < from) return;

    bulkForm.days = Math.round((to - from) / 86400000) + 1;

    if (! bulkForm.movement_date) bulkForm.movement_date = bulkForm.from_date;
}

function submitBulk() {
    bulkForm.transform((data) => ({
        ...data,
        employee_ids: selected.value,
        from_date: data.from_date === '' ? null : data.from_date,
        to_date: data.to_date === '' ? null : data.to_date,
        notes: data.notes === '' ? null : data.notes,
    })).post(route('vacations.bulk'), {
        preserveScroll: true,
        onSuccess: () => { bulking.value = false; selected.value = []; },
    });
}

// A quién NO le alcanza el saldo para lo que se va a rebajar. Se ve antes de
// aplicar, no después de que el proceso rebote.
const bulkShort = computed(() => {
    const days = parseFloat(bulkForm.days);

    if (! days || days <= 0 || bulkForm.type === 'adjustment') return [];

    return props.employees
        .filter((e) => selected.value.includes(e.id) && e.balance < days);
});
</script>

<template>
    <Head title="Vacaciones" />

    <AppLayout title="Vacaciones">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
                <button type="button" class="btn btn-ghost" :disabled="!selected.length" @click="openBulk()">
                    <UsersIcon /> Movimiento masivo
                </button>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar trabajador" aria-label="Buscar trabajador">
                <span class="muted small">
                    {{ visible.length }} trabajador(es)
                    <template v-if="selected.length"> · <strong>{{ selected.length }} seleccionado(s)</strong></template>
                </span>
            </div>
        </div>

        <div v-if="page.props.errors?.movement" class="flash flash-error">{{ page.props.errors.movement }}</div>

        <p class="hint">
            El saldo es la <strong>suma de los movimientos</strong>, no un campo que alguien mantiene: cada día
            acumulado se puede rastrear hasta el período que lo acreditó. Las acreditaciones las genera solo el
            cálculo de cada planilla, proporcionales a los días efectivamente cubiertos.
        </p>

        <p v-if="negative.length" class="flash flash-warning">
            {{ negative.length }} trabajador(es) tienen saldo negativo: disfrutaron días que todavía no habían
            ganado. Es legítimo si fue una decisión, pero conviene revisarlo.
        </p>

        <div class="stat-row">
            <div class="stat">
                <span class="stat-label">Días acumulados</span>
                <span class="stat-value">{{ totalDays.toFixed(2) }}</span>
            </div>
            <div class="stat strong">
                <span class="stat-label">Valor si se pagaran hoy</span>
                <span class="stat-value">{{ formatMoney(totalLiability) }}</span>
                <span class="stat-note">es un pasivo, no una estadística</span>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Ingreso</th>
                            <th class="num">Días acumulados</th>
                            <th class="num">Valor acumulado</th>
                            <th class="pick">
                                <input type="checkbox" :checked="allVisibleSelected" aria-label="Seleccionar todos para el movimiento masivo" @change="toggleAll">
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in visible"
                            :key="e.id"
                            class="clickable-row"
                            :class="{ picked: selected.includes(e.id) }"
                            tabindex="0"
                            @click="openEmployee(e)"
                            @keydown.enter.self="openEmployee(e)"
                            @keydown.space.self.prevent="openEmployee(e)"
                        >
                            <td><span class="code">{{ e.code }}</span> — {{ e.name }}</td>
                            <td data-label="Ingreso" class="code">{{ e.hire_date }}</td>
                            <td data-label="Días acumulados" class="num strong" :class="{ negative: e.balance < 0 }">{{ e.balance.toFixed(2) }}</td>
                            <td data-label="Valor acumulado" class="num">{{ formatMoney(e.balance * parseFloat(e.daily_rate)) }}</td>
                            <!-- La casilla es la selección del movimiento masivo, no una acción de la fila. -->
                            <td data-label="Seleccionar" class="pick" @click.stop>
                                <input v-model="selected" type="checkbox" :value="e.id" :aria-label="`Seleccionar a ${e.name}`">
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="5" class="muted empty-row">Sin trabajadores.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h3 class="block-title">Últimos movimientos <span class="muted small">({{ movements.length }})</span></h3>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Trabajador</th>
                            <th>Tipo</th>
                            <th class="num">Días</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in movements"
                            :key="m.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openMovement(m)"
                            @keydown.enter="openMovement(m)"
                            @keydown.space.prevent="openMovement(m)"
                        >
                            <td class="code">{{ m.movement_date }}</td>
                            <td data-label="Trabajador" class="small">{{ m.employee_code }} — {{ m.employee_name }}</td>
                            <td data-label="Tipo" class="small">
                                {{ m.type_label }}
                                <span v-if="m.is_automatic" class="badge badge-neutral auto">auto</span>
                            </td>
                            <td data-label="Días" class="num" :class="{ negative: m.days < 0 }">{{ m.days.toFixed(4) }}</td>
                            <td data-label="Monto" class="num small">{{ m.amount ? formatMoney(m.amount) : '—' }}</td>
                        </tr>
                        <tr v-if="!movements.length">
                            <td colspan="5" class="muted empty-row">Sin movimientos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ficha del trabajador -->
        <DetailModal :open="!!selectedEmployee" :title="selectedEmployee ? `${selectedEmployee.code} — ${selectedEmployee.name}` : ''" @close="closeEmployee">
            <dl v-if="selectedEmployee" class="detail-list">
                <div>
                    <dt>Puesto</dt>
                    <dd>{{ selectedEmployee.position ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Centro de costo</dt>
                    <dd>{{ selectedEmployee.cost_center ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Ingreso</dt>
                    <dd>{{ selectedEmployee.hire_date }}</dd>
                </div>
                <div>
                    <dt>Antigüedad</dt>
                    <dd>{{ selectedEmployee.years_of_service }}</dd>
                </div>
                <div>
                    <dt>Días acumulados</dt>
                    <dd :class="{ negative: selectedEmployee.balance < 0 }">{{ selectedEmployee.balance.toFixed(2) }}</dd>
                </div>
                <div>
                    <dt>Valor del día</dt>
                    <dd>{{ formatMoney(selectedEmployee.daily_rate) }}</dd>
                </div>
                <div>
                    <dt>Valor acumulado</dt>
                    <dd>{{ formatMoney(selectedEmployee.balance * parseFloat(selectedEmployee.daily_rate)) }}</dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selectedEmployee">
                    <Link :href="route('employees.show', selectedEmployee.id)" class="btn btn-ghost"><UserIcon /> Ver ficha</Link>
                    <button type="button" class="btn btn-primary" @click="registerFor(selectedEmployee)"><PlusIcon /> Registrar movimiento</button>
                </template>
            </template>
        </DetailModal>

        <!-- Ficha del movimiento -->
        <DetailModal :open="!!selectedMovement" :title="selectedMovement ? `${selectedMovement.type_label} — ${selectedMovement.employee_name}` : ''" @close="closeMovement">
            <template #badge>
                <span v-if="selectedMovement?.is_automatic" class="badge badge-neutral">automático</span>
            </template>

            <dl v-if="selectedMovement" class="detail-list">
                <div>
                    <dt>Fecha</dt>
                    <dd>{{ selectedMovement.movement_date }}</dd>
                </div>
                <div>
                    <dt>Días</dt>
                    <dd :class="{ negative: selectedMovement.days < 0 }">{{ selectedMovement.days.toFixed(4) }}</dd>
                </div>
                <div>
                    <dt>Rango</dt>
                    <dd>{{ selectedMovement.from_date ? `${selectedMovement.from_date} a ${selectedMovement.to_date}` : '—' }}</dd>
                </div>
                <div>
                    <dt>Monto</dt>
                    <dd>{{ selectedMovement.amount ? formatMoney(selectedMovement.amount) : '—' }}</dd>
                </div>
                <div>
                    <dt>Notas</dt>
                    <dd>{{ selectedMovement.notes ?? '—' }}</dd>
                </div>
            </dl>
            <p v-if="selectedMovement?.is_automatic" class="muted small modal-note">
                Lo generó el cálculo de la planilla: se corrige recalculando el período, no eliminándolo.
            </p>

            <template #actions>
                <button
                    v-if="selectedMovement && !selectedMovement.is_automatic"
                    type="button" class="btn btn-ghost btn-danger-text" @click="destroy"
                >Eliminar</button>
            </template>
        </DetailModal>

        <!-- Movimiento masivo -->
        <DetailModal :open="bulking" :title="`Movimiento para ${selected.length} trabajador(es)`" @close="bulking = false">
            <form id="vacation-bulk-form" @submit.prevent="submitBulk">
                <p class="hint small">
                    El mismo movimiento para todos los seleccionados: el cierre de fin de año, los saldos
                    iniciales, una acreditación por convenio.
                </p>

                <div class="field">
                    <label for="bulk-type">Tipo</label>
                    <select id="bulk-type" v-model="bulkForm.type" required>
                        <option value="taken">Disfrute</option>
                        <option value="paid">Pago en efectivo</option>
                        <option value="adjustment">Ajuste</option>
                    </select>
                </div>

                <div v-if="bulkForm.type !== 'adjustment'" class="field-row">
                    <div class="field">
                        <label for="bulk-from">Desde</label>
                        <input id="bulk-from" v-model="bulkForm.from_date" type="date" @change="syncBulkDays">
                    </div>
                    <div class="field">
                        <label for="bulk-to">Hasta</label>
                        <input id="bulk-to" v-model="bulkForm.to_date" type="date" @change="syncBulkDays">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="bulk-date">Fecha del movimiento</label>
                        <input id="bulk-date" v-model="bulkForm.movement_date" type="date" required>
                    </div>
                    <div class="field">
                        <label for="bulk-days">Días para cada uno</label>
                        <input id="bulk-days" v-model="bulkForm.days" type="number" step="0.01" required>
                        <span v-if="bulkForm.errors.days" class="error">{{ bulkForm.errors.days }}</span>
                    </div>
                </div>

                <p v-if="bulkShort.length" class="flash flash-warning">
                    A <strong>{{ bulkShort.length }}</strong> de los seleccionados no les alcanza el saldo:
                    {{ bulkShort.slice(0, 6).map((e) => `${e.code} (${e.balance.toFixed(2)})`).join(', ') }}<template v-if="bulkShort.length > 6">…</template>.
                    El proceso es <strong>todo o nada</strong>: sacalos de la selección, o registrales el
                    adelanto como ajuste.
                </p>

                <div class="field">
                    <label for="bulk-notes">Notas</label>
                    <input id="bulk-notes" v-model="bulkForm.notes" type="text" maxlength="255" placeholder="Cierre de fin de año, convenio…">
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="bulking = false">Cancelar</button>
                <button type="submit" form="vacation-bulk-form" class="btn btn-primary" :disabled="bulkForm.processing">
                    Aplicar a {{ selected.length }}
                </button>
            </template>
        </DetailModal>

        <!-- Registrar un movimiento -->
        <DetailModal :open="creating" title="Registrar movimiento de vacaciones" @close="creating = false">
            <form id="vacation-form" @submit.prevent="submit">
                <div class="field">
                    <label for="vac-employee">Trabajador</label>
                    <select id="vac-employee" v-model="form.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.code }} — {{ e.name }} ({{ e.balance.toFixed(2) }} día[s])
                        </option>
                    </select>
                    <span v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</span>
                </div>

                <div class="field">
                    <label for="vac-type">Tipo</label>
                    <select id="vac-type" v-model="form.type" required>
                        <option value="taken">Disfrute</option>
                        <option value="paid">Pago en efectivo</option>
                        <option value="settlement">Liquidación definitiva</option>
                        <option value="adjustment">Ajuste</option>
                    </select>
                    <span class="muted small">
                        Las acreditaciones no se digitan: las genera el cálculo de cada planilla.
                    </span>
                </div>

                <div v-if="form.type !== 'adjustment'" class="field-row">
                    <div class="field">
                        <label for="vac-from">Desde</label>
                        <input id="vac-from" v-model="form.from_date" type="date" @change="syncDays">
                    </div>
                    <div class="field">
                        <label for="vac-to">Hasta</label>
                        <input id="vac-to" v-model="form.to_date" type="date" @change="syncDays">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="vac-date">Fecha del movimiento</label>
                        <input id="vac-date" v-model="form.movement_date" type="date" required>
                    </div>
                    <div class="field">
                        <label for="vac-days">Días</label>
                        <input id="vac-days" v-model="form.days" type="number" step="0.01" required>
                        <span class="muted small">
                            {{ form.type === 'adjustment'
                                ? 'Positivo acredita, negativo rebaja.'
                                : 'Se guarda en negativo: rebaja el saldo.' }}
                        </span>
                        <span v-if="form.errors.days" class="error">{{ form.errors.days }}</span>
                    </div>
                </div>

                <div v-if="form.type === 'paid' || form.type === 'settlement'" class="field">
                    <label for="vac-amount">Monto pagado</label>
                    <input id="vac-amount" v-model="form.amount" type="number" step="0.01" min="0">
                </div>

                <div class="field">
                    <label for="vac-notes">Notas</label>
                    <input id="vac-notes" v-model="form.notes" type="text" maxlength="255">
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                <button type="submit" form="vacation-form" class="btn btn-primary" :disabled="form.processing">Registrar</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: 0.75rem; margin-bottom: 1rem; }

.stat {
    display: flex;
    flex-direction: column;
    padding: 0.7rem 1.1rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
}

.stat.strong { background: var(--color-surface-alt); }

.stat-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-muted);
}

.stat-value { font-size: 1.2rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat-note { font-size: 0.68rem; color: var(--color-text-muted); }

table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; white-space: nowrap; }
td.strong { font-weight: 600; }
.negative { color: var(--color-danger); }
.auto { margin-left: 0.3rem; font-size: 0.6rem; }
.modal-note { margin: 0.75rem 0 0; }

.pick { width: 2.5rem; text-align: center; }
.pick input { width: auto; margin: 0; }
tr.picked td { background: var(--color-surface-alt); }

@media screen and (max-width: 1024px) {
    .pick { width: auto; text-align: left; }
    tr.picked td { background: none; }
    .table-responsive tbody tr.picked { background: var(--color-surface-alt); }
}
</style>
