<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { formatMoney } from '../../../Utils/money';
import { ArrowRightIcon, CheckIcon, PlusIcon } from '@lucide/vue';

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

// Ficha de la liquidación (CLAUDE.md secc. 20): el desglose y el enlace a
// su pantalla, donde se aprueba y contabiliza.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.settlements);

const statusBadge = {
    draft: 'badge-neutral',
    approved: 'badge-warning',
    posted: 'badge-success',
    voided: 'badge-danger',
};
</script>

<template>
    <Head title="Liquidaciones laborales" />

    <AppLayout title="Liquidaciones laborales">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar trabajador o causal" aria-label="Buscar liquidación">
                <span class="muted small">{{ visible.length }} liquidación(es)</span>
            </div>
        </div>

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
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Salida</th>
                            <th>Causal</th>
                            <th class="num">Neto</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="s in visible"
                            :key="s.id"
                            class="clickable-row"
                            :class="{ voided: s.status === 'voided' }"
                            tabindex="0"
                            @click="openDetail(s)"
                            @keydown.enter="openDetail(s)"
                            @keydown.space.prevent="openDetail(s)"
                        >
                            <td><span class="code">{{ s.employee_code }}</span> — {{ s.employee_name }}</td>
                            <td data-label="Salida" class="code">{{ s.termination_date }}</td>
                            <td data-label="Causal" class="small">{{ s.reason_label }}</td>
                            <td data-label="Neto" class="num strong">{{ formatMoney(s.total_net) }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="statusBadge[s.status]">{{ s.status_label }}</span>
                            </td>
                        </tr>
                        <tr v-if="! visible.length">
                            <td colspan="5" class="muted empty-row">Todavía no hay liquidaciones.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `${selected.employee_code} — ${selected.employee_name}` : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="statusBadge[selected.status]">{{ selected.status_label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Fecha de salida</dt>
                    <dd>{{ selected.termination_date }}</dd>
                </div>
                <div>
                    <dt>Causal</dt>
                    <dd>{{ selected.reason_label }}</dd>
                </div>
                <div>
                    <dt>Bruto</dt>
                    <dd>{{ formatMoney(selected.total_gross) }}</dd>
                </div>
                <div>
                    <dt>Cargas sociales</dt>
                    <dd>{{ formatMoney(selected.total_ccss) }}</dd>
                </div>
                <div>
                    <dt>Impuesto</dt>
                    <dd>{{ formatMoney(selected.total_income_tax) }}</dd>
                </div>
                <div>
                    <dt>Otras deducciones</dt>
                    <dd>{{ formatMoney(selected.total_other_deductions) }}</dd>
                </div>
                <div>
                    <dt>Neto</dt>
                    <dd><strong>{{ formatMoney(selected.total_net) }}</strong></dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selected" :href="route('labor-settlements.show', selected.id)" class="btn btn-primary"><ArrowRightIcon /> Abrir</Link>
            </template>
        </DetailModal>

        <DetailModal :open="creating" title="Nueva liquidación" @close="creating = false">
            <form id="settlement-form" @submit.prevent="submit">
                <div class="field">
                    <label for="settle-employee">Trabajador</label>
                    <select id="settle-employee" v-model="form.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.code }} — {{ e.full_name }}
                        </option>
                    </select>
                    <span v-if="selectedEmployee" class="muted small">
                        Ingresó el {{ selectedEmployee.hire_date }}.
                    </span>
                    <span v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</span>
                </div>

                <div class="field">
                    <label for="settle-date">Fecha de salida</label>
                    <input id="settle-date" v-model="form.termination_date" type="date" required>
                    <span class="muted small">
                        Manda sobre todo: fija las tasas que rigen, la antigüedad y el mes con el que se
                        acumula el impuesto.
                    </span>
                    <span v-if="form.errors.termination_date" class="error">{{ form.errors.termination_date }}</span>
                </div>

                <div class="field">
                    <label for="settle-reason">Causal</label>
                    <select id="settle-reason" v-model="form.reason" required>
                        <option v-for="(label, value) in reasons" :key="value" :value="value">{{ label }}</option>
                    </select>
                </div>

                <div class="entitlement-box">
                    <span class="entitlement-title">Con esta causal se paga:</span>
                    <ul>
                        <li v-for="item in preview" :key="item.label" :class="{ off: ! item.on }">
                            <span class="mark"><CheckIcon v-if="item.on" aria-label="Sí" role="img" /><template v-else>—</template></span>
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
                    <label for="settle-detail">Hechos de la salida</label>
                    <textarea id="settle-detail" v-model="form.reason_detail" rows="3"
                        placeholder="Qué pasó, con fechas. Es lo que sostiene la causal si se reclama."></textarea>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                <button type="submit" form="settlement-form" class="btn btn-primary" :disabled="form.processing">
                    Calcular liquidación
                </button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; white-space: nowrap; }
.strong { font-weight: 700; }

.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 11rem), 1fr)); gap: 0.75rem; margin-bottom: 1rem; }

.stat {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.6rem 0.9rem;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.stat.strong { border-color: var(--color-info); background: var(--color-info-soft); }
.stat-label { font-size: 0.75rem; color: var(--color-text-muted); }
.stat-value { font-size: 1.15rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.stat-note { font-size: 0.7rem; color: var(--color-text-muted); }

.entitlement-box {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.6rem 0.9rem;
    background: var(--color-surface-alt);
    margin-bottom: 0.75rem;
}

.entitlement-title { font-size: 0.75rem; color: var(--color-text-muted); }
.entitlement-box ul { list-style: none; margin: 0.35rem 0 0; padding: 0; }
.entitlement-box li { display: flex; align-items: baseline; gap: 0.4rem; padding: 0.1rem 0; }
.entitlement-box li.off { color: var(--color-text-muted); }
.mark { width: 1rem; text-align: center; font-weight: 600; color: var(--color-success); }
.entitlement-box li.off .mark { color: var(--color-text-muted); }

tr.voided td { text-decoration: line-through; color: var(--color-text-muted); }
</style>
