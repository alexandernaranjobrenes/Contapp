<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../../Utils/money';

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

const creating = ref(false);
const editing = ref(null);
const createForm = useForm({ ...blank });
const editForm = useForm({ ...blank });
const activeForm = computed(() => (creating.value ? createForm : editForm));

// El orden de prioridad no se adivina: al elegir el tipo se propone el que
// corresponde, y quien sepa lo que hace puede cambiarlo.
function syncPriority() {
    activeForm.value.priority = props.defaultPriorities[activeForm.value.type] ?? 100;
}

function openCreate() {
    createForm.reset();
    syncPriority();
    creating.value = true;
}

function openEdit(deduction) {
    editForm.clearErrors();
    Object.keys(blank).forEach((key) => { editForm[key] = deduction[key] ?? blank[key]; });
    editing.value = deduction;
}

function normalize(data) {
    const out = { ...data };

    ['reference', 'payroll_concept_id', 'end_date', 'original_amount', 'balance',
        'installment_amount', 'installment_percentage', 'account_id', 'notes']
        .forEach((key) => { if (out[key] === '') out[key] = null; });

    return out;
}

function submitCreate() {
    createForm.transform(normalize).post(route('employee-deductions.store'), {
        onSuccess: () => (creating.value = false), preserveScroll: true,
    });
}

function submitEdit() {
    editForm.transform(normalize).put(route('employee-deductions.update', editing.value.id), {
        onSuccess: () => (editing.value = null), preserveScroll: true,
    });
}

function destroy(deduction) {
    if (! confirm(`¿Eliminar «${deduction.description}» de ${deduction.employee_name}?`)) return;

    router.delete(route('employee-deductions.destroy', deduction.id), { preserveScroll: true });
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
</script>

<template>
    <Head title="Deducciones y préstamos" />

    <AppLayout title="Deducciones y préstamos">
        <template #actions>
            <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.deduction" class="flash flash-error">{{ page.props.errors.deduction }}</div>

        <p class="hint">
            Un adelanto, un préstamo solidarista y un embargo son la misma cosa: un monto que se rebaja cada
            período. Se aplican solas al calcular la planilla, en <strong>orden de prioridad</strong>, y el motor
            se detiene antes de dejar el neto en negativo: lo que no cabe queda como saldo para el período
            siguiente.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">
                    {{ visible.length }} obligación(es) · saldo vivo {{ formatMoney(liveBalance) }}
                </span>
                <label class="check inline">
                    <input v-model="showSettled" type="checkbox">
                    Mostrar canceladas
                </label>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva obligación</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th class="right">Prior.</th>
                            <th>Trabajador</th>
                            <th>Tipo</th>
                            <th>Descripción</th>
                            <th>Referencia</th>
                            <th>Desde</th>
                            <th class="right">Cuota</th>
                            <th class="right">Otorgado</th>
                            <th class="right">Saldo</th>
                            <th class="right">Rebajos</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in visible" :key="d.id" :class="{ dim: d.status !== 'active' }">
                            <td class="right num small">{{ d.priority }}</td>
                            <td>
                                <Link :href="route('employees.show', d.employee_id)">{{ d.employee_code }}</Link>
                                <span class="muted small"> {{ d.employee_name }}</span>
                            </td>
                            <td class="small">{{ d.type_label }}</td>
                            <td>{{ d.description }}</td>
                            <td class="muted small">{{ d.reference ?? '—' }}</td>
                            <td class="num small">{{ d.start_date }}</td>
                            <td class="right num">
                                <template v-if="d.calculation === 'percentage'">{{ d.installment_percentage }}%</template>
                                <template v-else>{{ formatMoney(d.installment_amount) }}</template>
                            </td>
                            <td class="right num muted">{{ d.original_amount ? formatMoney(d.original_amount) : '—' }}</td>
                            <td class="right num">
                                <template v-if="d.balance !== null">{{ formatMoney(d.balance) }}</template>
                                <span v-else class="muted small">indefinida</span>
                            </td>
                            <td class="right num small muted">{{ d.applications_count }}</td>
                            <td class="small">{{ statusLabels[d.status] ?? d.status }}</td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEdit(d)">Editar</button>
                                <button
                                    v-if="!d.applications_count"
                                    type="button" class="btn btn-ghost btn-sm" @click="destroy(d)"
                                >Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="12" class="muted empty-row">
                                Sin obligaciones registradas.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating || editing" class="modal-backdrop" @click.self="creating = false; editing = null">
            <form class="modal card" @submit.prevent="creating ? submitCreate() : submitEdit()">
                <h2>{{ creating ? 'Nueva obligación' : 'Editar obligación' }}</h2>

                <div class="field">
                    <label>Trabajador</label>
                    <select v-model="activeForm.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                    </select>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Tipo</label>
                        <select v-model="activeForm.type" required @change="syncPriority">
                            <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Prioridad</label>
                        <input v-model="activeForm.priority" type="number" min="1" max="999" required>
                        <span class="hint small">Menor número se rebaja primero.</span>
                    </div>
                </div>

                <div class="field">
                    <label>Descripción</label>
                    <input v-model="activeForm.description" type="text" maxlength="255" required>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Referencia</label>
                        <input v-model="activeForm.reference" type="text" maxlength="60" placeholder="N.º de préstamo, expediente">
                    </div>
                    <div class="field">
                        <label>Concepto de planilla</label>
                        <select v-model="activeForm.payroll_concept_id">
                            <option value="">Sin concepto</option>
                            <option v-for="c in concepts" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <span class="hint small">Le da nombre y cuenta contable en la boleta.</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Desde</label>
                        <input v-model="activeForm.start_date" type="date" required>
                    </div>
                    <div class="field">
                        <label>Hasta</label>
                        <input v-model="activeForm.end_date" type="date">
                        <span class="hint small">Vacío = sin límite.</span>
                    </div>
                </div>

                <div v-if="settlingTypes.includes(activeForm.type)" class="field-row">
                    <div class="field">
                        <label>Monto otorgado</label>
                        <input v-model="activeForm.original_amount" type="number" step="0.01" min="0">
                    </div>
                    <div class="field">
                        <label>Saldo actual</label>
                        <input v-model="activeForm.balance" type="number" step="0.01" min="0">
                        <span class="hint small">Vacío arranca con el monto otorgado.</span>
                        <span v-if="activeForm.errors.balance" class="error">{{ activeForm.errors.balance }}</span>
                    </div>
                </div>

                <p v-else class="hint small">
                    Este tipo se rebaja <strong>indefinidamente</strong>: no lleva saldo y no se extingue solo.
                    Para que termine, ponele una fecha final o suspendelo.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label>Cómo se calcula la cuota</label>
                        <select v-model="activeForm.calculation" required>
                            <option value="amount">Monto fijo</option>
                            <option value="percentage">Porcentaje del bruto del período</option>
                        </select>
                    </div>
                    <div v-if="activeForm.calculation === 'amount'" class="field">
                        <label>Cuota</label>
                        <input v-model="activeForm.installment_amount" type="number" step="0.01" min="0.01" required>
                        <span v-if="activeForm.errors.installment_amount" class="error">{{ activeForm.errors.installment_amount }}</span>
                    </div>
                    <div v-else class="field">
                        <label>Porcentaje</label>
                        <input v-model="activeForm.installment_percentage" type="number" step="0.0001" min="0.0001" max="100" required>
                        <span v-if="activeForm.errors.installment_percentage" class="error">{{ activeForm.errors.installment_percentage }}</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Cuenta contable</label>
                        <select v-model="activeForm.account_id">
                            <option value="">Usar la del concepto</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Estado</label>
                        <select v-model="activeForm.status" required>
                            <option value="active">Activa</option>
                            <option value="suspended">Suspendida</option>
                            <option value="settled">Cancelada</option>
                            <option value="cancelled">Anulada</option>
                        </select>
                    </div>
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
.check.inline { margin: 0; white-space: nowrap; }
.dim { opacity: 0.55; }
.error { color: var(--color-danger); font-size: 0.76rem; }
</style>
