<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';
import { formatMoney } from '../../../Utils/money';
import { PencilIcon, PlusIcon, UserIcon } from '@lucide/vue';

const props = defineProps({
    deductions: { type: Array, default: () => [] },
    types: { type: Object, required: true },
    defaultPriorities: { type: Object, required: true },
    employees: { type: Array, default: () => [] },
    concepts: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const page = usePage();

const blank = {
    employee_id: '',
    type: 'loan',
    reference: '',
    description: '',
    payroll_concept_id: '',
    start_date: '',
    end_date: '',
    original_amount: '',
    balance: '',
    calculation: 'amount',
    installment_amount: '',
    installment_percentage: '',
    priority: 60,
    account_id: '',
    status: 'active',
    notes: '',
};

function normalize(data) {
    const out = { ...data };

    ['reference', 'payroll_concept_id', 'end_date', 'original_amount', 'balance',
        'installment_amount', 'installment_percentage', 'account_id', 'notes']
        .forEach((key) => { if (out[key] === '') out[key] = null; });

    return out;
}

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21).
const { mode, selected, modalOpen, form, openCreate: openCrudCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.deductions,
    // El orden de prioridad no se adivina: al elegir el tipo se propone el
    // que corresponde, y quien sepa lo que hace puede cambiarlo.
    defaults: () => ({ ...blank, priority: props.defaultPriorities[blank.type] ?? 100 }),
    toForm: (d) => Object.fromEntries(Object.keys(blank).map((key) => [key, d[key] ?? blank[key]])),
    store: () => route('employee-deductions.store'),
    update: (d) => route('employee-deductions.update', d.id),
    storePayload: normalize,
    updatePayload: normalize,
});

function openCreate() {
    openCrudCreate();
}

function syncPriority() {
    form.priority = props.defaultPriorities[form.type] ?? 100;
}

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva obligación';
    return selected.value ? `${selected.value.description} — ${selected.value.employee_name}` : '';
});

function destroy() {
    const d = selected.value;

    confirmAction({
        title: 'Eliminar obligación',
        message: `«${d.description}» de ${d.employee_name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('employee-deductions.destroy', d.id), { preserveScroll: true }),
    });
}

const showSettled = ref(false);

const visible = computed(
    () => props.deductions.filter((d) => showSettled.value || ['active', 'suspended'].includes(d.status))
);

const liveBalance = computed(() => props.deductions
    .filter((d) => d.status === 'active' && d.balance !== null)
    .reduce((sum, d) => sum + (parseFloat(d.balance) || 0), 0));

// Una obligación sin saldo se rebaja para siempre; con saldo se extingue
// sola. Es la distinción que más confunde y por eso se explica en pantalla.
const settlingTypes = ['advance', 'loan'];

const statusLabels = {
    active: 'Activa', suspended: 'Suspendida', settled: 'Cancelada', cancelled: 'Anulada',
};

const statusBadge = {
    active: 'badge-success', suspended: 'badge-warning', settled: 'badge-neutral', cancelled: 'badge-neutral',
};

function installment(d) {
    return d.calculation === 'percentage' ? `${d.installment_percentage}%` : formatMoney(d.installment_amount);
}
</script>

<template>
    <Head title="Deducciones y préstamos" />

    <AppLayout title="Deducciones y préstamos">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <label class="check">
                    <input v-model="showSettled" type="checkbox">
                    Mostrar canceladas
                </label>
                <span class="muted small">
                    {{ visible.length }} obligación(es) · saldo vivo {{ formatMoney(liveBalance) }}
                </span>
            </div>
        </div>

        <div v-if="page.props.errors?.deduction" class="flash flash-error">{{ page.props.errors.deduction }}</div>

        <p class="hint">
            Un adelanto, un préstamo solidarista y un embargo son la misma cosa: un monto que se rebaja cada
            período. Se aplican solas al calcular la planilla, en <strong>orden de prioridad</strong>, y el motor
            se detiene antes de dejar el neto en negativo: lo que no cabe queda como saldo para el período
            siguiente.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Descripción</th>
                            <th class="num">Cuota</th>
                            <th class="num">Saldo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in visible"
                            :key="d.id"
                            class="clickable-row"
                            :class="{ dim: d.status !== 'active' }"
                            tabindex="0"
                            @click="openDetail(d)"
                            @keydown.enter="openDetail(d)"
                            @keydown.space.prevent="openDetail(d)"
                        >
                            <td><span class="code">{{ d.employee_code }}</span> — {{ d.employee_name }}</td>
                            <td data-label="Descripción">
                                {{ d.description }}
                                <span class="block muted small">{{ d.type_label }} · prioridad {{ d.priority }}</span>
                            </td>
                            <td data-label="Cuota" class="num">{{ installment(d) }}</td>
                            <td data-label="Saldo" class="num">
                                <template v-if="d.balance !== null">{{ formatMoney(d.balance) }}</template>
                                <span v-else class="muted small">indefinida</span>
                            </td>
                            <td data-label="Estado"><span class="badge" :class="statusBadge[d.status]">{{ statusLabels[d.status] ?? d.status }}</span></td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="5" class="muted empty-row">
                                Sin obligaciones registradas.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" wide :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="statusBadge[selected.status]">{{ statusLabels[selected.status] ?? selected.status }}</span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Trabajador</dt>
                    <dd>{{ selected.employee_code }} — {{ selected.employee_name }}</dd>
                </div>
                <div>
                    <dt>Tipo</dt>
                    <dd>{{ selected.type_label }}</dd>
                </div>
                <div>
                    <dt>Prioridad</dt>
                    <dd>{{ selected.priority }}</dd>
                </div>
                <div>
                    <dt>Referencia</dt>
                    <dd>{{ selected.reference ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Desde</dt>
                    <dd>{{ selected.start_date }}</dd>
                </div>
                <div>
                    <dt>Hasta</dt>
                    <dd>{{ selected.end_date ?? 'Sin límite' }}</dd>
                </div>
                <div>
                    <dt>Cuota</dt>
                    <dd>{{ installment(selected) }}</dd>
                </div>
                <div>
                    <dt>Otorgado</dt>
                    <dd>{{ selected.original_amount ? formatMoney(selected.original_amount) : '—' }}</dd>
                </div>
                <div>
                    <dt>Saldo</dt>
                    <dd>{{ selected.balance !== null ? formatMoney(selected.balance) : 'Indefinida' }}</dd>
                </div>
                <div>
                    <dt>Rebajos aplicados</dt>
                    <dd>{{ selected.applications_count }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="deduction-form" @submit.prevent="submit">
                <div class="form-grid">
                    <div class="field span-full">
                        <label for="ded-employee">Trabajador</label>
                        <select id="ded-employee" v-model="form.employee_id" required>
                            <option value="">Elegí</option>
                            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                        </select>
                        <span v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</span>
                    </div>

                    <div class="field">
                        <label for="ded-type">Tipo</label>
                        <select id="ded-type" v-model="form.type" required @change="syncPriority">
                            <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="ded-priority">Prioridad (menor número se rebaja primero)</label>
                        <input id="ded-priority" v-model="form.priority" type="number" min="1" max="999" required>
                    </div>

                    <div class="field span-full">
                        <label for="ded-description">Descripción</label>
                        <input id="ded-description" v-model="form.description" type="text" maxlength="255" required>
                        <span v-if="form.errors.description" class="error">{{ form.errors.description }}</span>
                    </div>

                    <div class="field">
                        <label for="ded-reference">Referencia</label>
                        <input id="ded-reference" v-model="form.reference" type="text" maxlength="60" placeholder="N.º de préstamo, expediente">
                    </div>
                    <div class="field">
                        <label for="ded-concept">Concepto de planilla</label>
                        <select id="ded-concept" v-model="form.payroll_concept_id">
                            <option value="">Sin concepto</option>
                            <option v-for="c in concepts" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <span class="muted small">Le da nombre y cuenta contable en la boleta.</span>
                    </div>

                    <div class="field">
                        <label for="ded-start">Desde</label>
                        <input id="ded-start" v-model="form.start_date" type="date" required>
                    </div>
                    <div class="field">
                        <label for="ded-end">Hasta (vacío = sin límite)</label>
                        <input id="ded-end" v-model="form.end_date" type="date">
                    </div>

                    <template v-if="settlingTypes.includes(form.type)">
                        <div class="field">
                            <label for="ded-original">Monto otorgado</label>
                            <input id="ded-original" v-model="form.original_amount" type="number" step="0.01" min="0">
                        </div>
                        <div class="field">
                            <label for="ded-balance">Saldo actual (vacío = el monto otorgado)</label>
                            <input id="ded-balance" v-model="form.balance" type="number" step="0.01" min="0">
                            <span v-if="form.errors.balance" class="error">{{ form.errors.balance }}</span>
                        </div>
                    </template>

                    <p v-else class="hint small span-full">
                        Este tipo se rebaja <strong>indefinidamente</strong>: no lleva saldo y no se extingue solo.
                        Para que termine, ponele una fecha final o suspendelo.
                    </p>

                    <div class="field">
                        <label for="ded-calculation">Cómo se calcula la cuota</label>
                        <select id="ded-calculation" v-model="form.calculation" required>
                            <option value="amount">Monto fijo</option>
                            <option value="percentage">Porcentaje del bruto del período</option>
                        </select>
                    </div>
                    <div v-if="form.calculation === 'amount'" class="field">
                        <label for="ded-installment">Cuota</label>
                        <input id="ded-installment" v-model="form.installment_amount" type="number" step="0.01" min="0.01" required>
                        <span v-if="form.errors.installment_amount" class="error">{{ form.errors.installment_amount }}</span>
                    </div>
                    <div v-else class="field">
                        <label for="ded-percentage">Porcentaje</label>
                        <input id="ded-percentage" v-model="form.installment_percentage" type="number" step="0.0001" min="0.0001" max="100" required>
                        <span v-if="form.errors.installment_percentage" class="error">{{ form.errors.installment_percentage }}</span>
                    </div>

                    <div class="field">
                        <label for="ded-account">Cuenta contable</label>
                        <select id="ded-account" v-model="form.account_id">
                            <option value="">Usar la del concepto</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="ded-status">Estado</label>
                        <select id="ded-status" v-model="form.status" required>
                            <option value="active">Activa</option>
                            <option value="suspended">Suspendida</option>
                            <option value="settled">Cancelada</option>
                            <option value="cancelled">Anulada</option>
                        </select>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button
                        v-if="!selected.applications_count"
                        type="button" class="btn btn-ghost btn-danger-text" @click="destroy"
                    >Eliminar</button>
                    <Link :href="route('employees.show', selected.employee_id)" class="btn btn-ghost"><UserIcon /> Ver trabajador</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="deduction-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.block { display: block; }
.dim { opacity: 0.55; }
</style>
