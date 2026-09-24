<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

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

function destroy(movement) {
    if (! confirm('¿Eliminar este movimiento?')) return;

    router.delete(route('vacations.destroy', movement.id), { preserveScroll: true });
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
</script>

<template>
    <Head title="Vacaciones" />

    <AppLayout title="Vacaciones">
        <template #actions>
            <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
        </template>

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
            <div class="card-header">
                <input v-model="search" type="search" placeholder="Buscar trabajador" class="search-input">
                <span class="muted">{{ visible.length }} trabajador(es)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Registrar movimiento</button>
            </div>

            <div class="table-scroll freeze-2">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Trabajador</th>
                            <th>Puesto</th>
                            <th>C. costo</th>
                            <th>Ingreso</th>
                            <th class="right">Antigüedad</th>
                            <th class="right">Días acumulados</th>
                            <th class="right">Valor del día</th>
                            <th class="right">Valor acumulado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in visible" :key="e.id">
                            <td class="num">{{ e.code }}</td>
                            <td><Link :href="route('employees.show', e.id)">{{ e.name }}</Link></td>
                            <td class="muted small">{{ e.position ?? '—' }}</td>
                            <td class="muted small">{{ e.cost_center ?? '—' }}</td>
                            <td class="num small">{{ e.hire_date }}</td>
                            <td class="right num small">{{ e.years_of_service }}</td>
                            <td class="right num strong" :class="{ negative: e.balance < 0 }">{{ e.balance.toFixed(2) }}</td>
                            <td class="right num muted">{{ formatMoney(e.daily_rate) }}</td>
                            <td class="right num">{{ formatMoney(e.balance * parseFloat(e.daily_rate)) }}</td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openCreate(e.id)">Registrar</button>
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="10" class="muted empty-row">Sin trabajadores.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <section class="card">
            <div class="card-header">
                <h3>Últimos movimientos</h3>
                <span class="muted small">{{ movements.length }}</span>
            </div>

            <div class="table-scroll compact">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Trabajador</th>
                            <th>Tipo</th>
                            <th class="right">Días</th>
                            <th>Rango</th>
                            <th class="right">Monto</th>
                            <th>Notas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in movements" :key="m.id">
                            <td class="num small">{{ m.movement_date }}</td>
                            <td class="small">{{ m.employee_code }} — {{ m.employee_name }}</td>
                            <td class="small">
                                {{ m.type_label }}
                                <span v-if="m.is_automatic" class="badge badge-neutral auto">auto</span>
                            </td>
                            <td class="right num" :class="{ negative: m.days < 0 }">{{ m.days.toFixed(4) }}</td>
                            <td class="muted small">
                                <template v-if="m.from_date">{{ m.from_date }} a {{ m.to_date }}</template>
                                <template v-else>—</template>
                            </td>
                            <td class="right num small">{{ m.amount ? formatMoney(m.amount) : '—' }}</td>
                            <td class="muted small">{{ m.notes ?? '—' }}</td>
                            <td class="row-actions">
                                <button
                                    v-if="!m.is_automatic"
                                    type="button" class="btn btn-ghost btn-sm" @click="destroy(m)"
                                >Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!movements.length">
                            <td colspan="8" class="muted empty-row">Sin movimientos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal card" @submit.prevent="submit">
                <h2>Registrar movimiento de vacaciones</h2>

                <div class="field">
                    <label>Trabajador</label>
                    <select v-model="form.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.code }} — {{ e.name }} ({{ e.balance.toFixed(2) }} día[s])
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>Tipo</label>
                    <select v-model="form.type" required>
                        <option value="taken">Disfrute</option>
                        <option value="paid">Pago en efectivo</option>
                        <option value="adjustment">Ajuste</option>
                    </select>
                    <span class="hint small">
                        Las acreditaciones no se digitan: las genera el cálculo de cada planilla.
                    </span>
                </div>

                <div v-if="form.type !== 'adjustment'" class="field-row">
                    <div class="field">
                        <label>Desde</label>
                        <input v-model="form.from_date" type="date" @change="syncDays">
                    </div>
                    <div class="field">
                        <label>Hasta</label>
                        <input v-model="form.to_date" type="date" @change="syncDays">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Fecha del movimiento</label>
                        <input v-model="form.movement_date" type="date" required>
                    </div>
                    <div class="field">
                        <label>Días</label>
                        <input v-model="form.days" type="number" step="0.01" required>
                        <span class="hint small">
                            {{ form.type === 'adjustment'
                                ? 'Positivo acredita, negativo rebaja.'
                                : 'Se guarda en negativo: rebaja el saldo.' }}
                        </span>
                        <span v-if="form.errors.days" class="error">{{ form.errors.days }}</span>
                    </div>
                </div>

                <div v-if="form.type === 'paid'" class="field">
                    <label>Monto pagado</label>
                    <input v-model="form.amount" type="number" step="0.01" min="0">
                </div>

                <div class="field">
                    <label>Notas</label>
                    <input v-model="form.notes" type="text" maxlength="255">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Registrar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.stat-row { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }

.stat {
    display: flex;
    flex-direction: column;
    padding: 0.7rem 1.1rem;
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    background: var(--color-surface);
    min-width: 12rem;
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

.card-header h3 { margin: 0; font-size: 0.9rem; }
.search-input { min-width: 18rem; flex: 1; }
.table-scroll.compact { max-height: 22rem; }

td.strong { font-weight: 600; }
.negative { color: var(--color-danger); }
.auto { margin-left: 0.3rem; font-size: 0.6rem; }
.error { color: var(--color-danger); font-size: 0.76rem; }
</style>
