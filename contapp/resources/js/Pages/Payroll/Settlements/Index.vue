<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    settlements: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    reasons: { type: Object, required: true },
    statuses: { type: Object, required: true },
    entitlements: { type: Object, default: () => ({}) },
    kinds: { type: Object, default: () => ({}) },
});

const page = usePage();

const creating = ref(false);

const form = useForm({
    employee_id: '',
    termination_date: '',
    reason: 'despido_con_responsabilidad',
    reason_detail: '',
});

function openCreate() {
    form.reset();
    form.clearErrors();
    creating.value = true;
}

function submit() {
    form.transform((data) => ({
        ...data,
        reason_detail: data.reason_detail === '' ? null : data.reason_detail,
    })).post(route('labor-settlements.store'), { onSuccess: () => (creating.value = false) });
}

// Qué extremos va a pagar la causal elegida. Se muestra ANTES de crear la
// liquidación porque es la decisión que más pesa: es la diferencia entre
// pagar cuatro extremos y pagar dos, y quien la escoge tiene que ver la
// consecuencia en el momento de escogerla, no después de calcular.
const preview = computed(() => {
    const entitled = props.entitlements[form.reason];

    if (! entitled) return [];

    return [
        { label: 'Aguinaldo proporcional', on: entitled.christmas_bonus, always: true },
        { label: 'Vacaciones no disfrutadas', on: entitled.vacation, always: true },
        { label: 'Preaviso', on: entitled.notice, always: false },
        { label: 'Cesantía', on: entitled.severance, always: false },
        { label: 'Indemnización adicional', on: entitled.indemnity, always: false },
    ];
});

const selectedEmployee = computed(
    () => props.employees.find((e) => e.id === Number(form.employee_id)) ?? null
);

const search = ref('');

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (! needle) return props.settlements;

    return props.settlements.filter(
        (s) => `${s.employee_code} ${s.employee_name} ${s.reason_label}`.toLowerCase().includes(needle)
    );
});

const totalNet = computed(
    () => props.settlements
        .filter((s) => s.status !== 'voided')
        .reduce((sum, s) => sum + (parseFloat(s.total_net) || 0), 0)
);

const drafts = computed(() => props.settlements.filter((s) => s.status === 'draft').length);
</script>

<template>
    <Head title="Liquidaciones laborales" />

    <AppLayout title="Liquidaciones laborales">
        <template #actions>
            <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
        </template>

        <div v-if="page.props.errors?.payroll" class="flash flash-error">{{ page.props.errors.payroll }}</div>

        <p class="hint">
            Qué extremos se pagan lo decide la <strong>causal</strong>, no una casilla: el aguinaldo y las
            vacaciones son irrenunciables y se pagan siempre —hasta en un despido con justa causa— mientras el
            preaviso y la cesantía dependen de quién y por qué terminó la relación.
            Las bases salen del <strong>promedio de lo devengado</strong> en la planilla, no del salario de la
            ficha: si el trabajador hizo horas extra, liquidarlo con la ficha le paga de menos.
        </p>

        <div class="stat-row">
            <div class="stat">
                <span class="stat-label">Liquidaciones</span>
                <span class="stat-value">{{ settlements.length }}</span>
            </div>
            <div v-if="drafts" class="stat">
                <span class="stat-label">En borrador</span>
                <span class="stat-value">{{ drafts }}</span>
                <span class="stat-note">sin aprobar ni contabilizar</span>
            </div>
            <div class="stat strong">
                <span class="stat-label">Neto liquidado</span>
                <span class="stat-value">{{ formatMoney(totalNet) }}</span>
                <span class="stat-note">sin contar las anuladas</span>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <input v-model="search" type="search" placeholder="Buscar trabajador o causal" class="search-input">
                <span class="muted">{{ visible.length }} liquidación(es)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva liquidación</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Trabajador</th>
                            <th>Salida</th>
                            <th>Causal</th>
                            <th class="right">Bruto</th>
                            <th class="right">Cargas</th>
                            <th class="right">Impuesto</th>
                            <th class="right">Deducciones</th>
                            <th class="right">Neto</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in visible" :key="s.id" :class="{ voided: s.status === 'voided' }">
                            <td class="num">{{ s.employee_code }}</td>
                            <td>
                                <Link :href="route('labor-settlements.show', s.id)">{{ s.employee_name }}</Link>
                            </td>
                            <td class="num small">{{ s.termination_date }}</td>
                            <td class="small">{{ s.reason_label }}</td>
                            <td class="right">{{ formatMoney(s.total_gross) }}</td>
                            <td class="right muted">{{ formatMoney(s.total_ccss) }}</td>
                            <td class="right muted">{{ formatMoney(s.total_income_tax) }}</td>
                            <td class="right muted">{{ formatMoney(s.total_other_deductions) }}</td>
                            <td class="right strong">{{ formatMoney(s.total_net) }}</td>
                            <td>
                                <span class="badge" :class="`badge-${s.status}`">{{ s.status_label }}</span>
                            </td>
                            <td class="row-actions">
                                <Link :href="route('labor-settlements.show', s.id)" class="btn btn-ghost btn-sm">Abrir</Link>
                            </td>
                        </tr>
                        <tr v-if="! visible.length">
                            <td colspan="11" class="muted center">Todavía no hay liquidaciones.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal card" @submit.prevent="submit">
                <h2>Nueva liquidación</h2>

                <div class="field">
                    <label>Trabajador</label>
                    <select v-model="form.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.code }} — {{ e.full_name }}
                        </option>
                    </select>
                    <span v-if="selectedEmployee" class="hint small">
                        Ingresó el {{ selectedEmployee.hire_date }}.
                    </span>
                    <span v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</span>
                </div>

                <div class="field">
                    <label>Fecha de salida</label>
                    <input v-model="form.termination_date" type="date" required>
                    <span class="hint small">
                        Manda sobre todo: fija las tasas que rigen, la antigüedad y el mes con el que se
                        acumula el impuesto.
                    </span>
                    <span v-if="form.errors.termination_date" class="error">{{ form.errors.termination_date }}</span>
                </div>

                <div class="field">
                    <label>Causal</label>
                    <select v-model="form.reason" required>
                        <option v-for="(label, value) in reasons" :key="value" :value="value">{{ label }}</option>
                    </select>
                </div>

                <div class="entitlement-box">
                    <span class="entitlement-title">Con esta causal se paga:</span>
                    <ul>
                        <li v-for="item in preview" :key="item.label" :class="{ off: ! item.on }">
                            <span class="mark">{{ item.on ? '✓' : '—' }}</span>
                            {{ item.label }}
                            <span v-if="item.on && item.always" class="muted small">irrenunciable</span>
                        </li>
                    </ul>
                    <p v-if="form.reason === 'renuncia'" class="muted small">
                        En una renuncia el preaviso lo debe el <strong>trabajador</strong>. No se le paga, y
                        rebajárselo requiere su autorización expresa.
                    </p>
                </div>

                <div class="field">
                    <label>Hechos de la salida</label>
                    <textarea v-model="form.reason_detail" rows="3"
                        placeholder="Qué pasó, con fechas. Es lo que sostiene la causal si se reclama."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">
                        Calcular liquidación
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.stat-row { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }

.stat {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 0.6rem 0.9rem;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    min-width: 11rem;
}

.stat.strong { border-color: #c7d2fe; background: #eef2ff; }
.stat-label { font-size: 0.75rem; color: #6b7280; }
.stat-value { font-size: 1.15rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat-note { font-size: 0.7rem; color: #6b7280; }

.entitlement-box {
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 0.6rem 0.9rem;
    background: #f9fafb;
    margin-bottom: 0.75rem;
}

.entitlement-title { font-size: 0.75rem; color: #6b7280; }
.entitlement-box ul { list-style: none; margin: 0.35rem 0 0; padding: 0; }
.entitlement-box li { display: flex; align-items: baseline; gap: 0.4rem; padding: 0.1rem 0; }
.entitlement-box li.off { color: #9ca3af; }
.mark { width: 1rem; text-align: center; font-weight: 600; }
.entitlement-box li.off .mark { color: #d1d5db; }

.badge {
    display: inline-block;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    font-size: 0.72rem;
    border: 1px solid #d1d5db;
    white-space: nowrap;
}

.badge-draft { background: #f3f4f6; color: #374151; }
.badge-approved { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.badge-posted { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
.badge-voided { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

tr.voided td { text-decoration: line-through; color: #9ca3af; }
.center { text-align: center; }
</style>
