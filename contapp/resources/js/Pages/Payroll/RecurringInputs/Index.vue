<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    items: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    concepts: { type: Array, default: () => [] },
    assignableCount: { type: Number, default: 0 },
    conceptCount: { type: Number, default: 0 },
});

const page = usePage();

const blank = {
    employee_id: '',
    payroll_concept_id: '',
    amount: '',
    quantity: '',
    start_date: '',
    end_date: '',
    notes: '',
    status: 'active',
};

const creating = ref(false);
const editing = ref(null);
const createForm = useForm({ ...blank });
const editForm = useForm({ ...blank });
const activeForm = computed(() => (creating.value ? createForm : editForm));

const selectedConcept = computed(
    () => props.concepts.find((c) => c.id === Number(activeForm.value.payroll_concept_id)) ?? null
);

const byHours = computed(() => selectedConcept.value?.calculation === 'hours');

function openCreate() {
    createForm.reset();
    creating.value = true;
}

function openEdit(item) {
    editForm.clearErrors();
    Object.keys(blank).forEach((key) => { editForm[key] = item[key] ?? blank[key]; });
    editing.value = item;
}

function normalize(data) {
    return {
        ...data,
        amount: data.amount === '' ? null : data.amount,
        quantity: data.quantity === '' ? null : data.quantity,
        end_date: data.end_date === '' ? null : data.end_date,
        notes: data.notes === '' ? null : data.notes,
    };
}

function submitCreate() {
    createForm.transform(normalize).post(route('recurring-inputs.store'), {
        onSuccess: () => (creating.value = false), preserveScroll: true,
    });
}

function submitEdit() {
    editForm.transform(normalize).put(route('recurring-inputs.update', editing.value.id), {
        onSuccess: () => (editing.value = null), preserveScroll: true,
    });
}

function destroy(item) {
    if (! confirm(`¿Eliminar el rubro fijo ${item.concept_code} de ${item.employee_name}?`)) return;

    router.delete(route('recurring-inputs.destroy', item.id), { preserveScroll: true });
}

const showSuspended = ref(false);

const visible = computed(
    () => props.items.filter((i) => showSuspended.value || i.status === 'active')
);
</script>

<template>
    <Head title="Rubros fijos" />

    <AppLayout title="Rubros fijos">
        <template #actions>
            <Link :href="route('payroll-periods.index')" class="btn btn-ghost">Períodos de planilla</Link>
            <Link :href="route('payroll-settings.index')" class="btn btn-ghost">Configuración</Link>
        </template>

        <DocumentToolbar :can-create="concepts.length > 0" @new="openCreate()" />

        <div v-if="page.props.errors?.recurring" class="flash flash-error">{{ page.props.errors.recurring }}</div>

        <p class="hint">
            Lo que se repite cada período sin volverse a digitar: una bonificación de conectividad, un salario en
            especie, un rebajo acordado. Digitar veinticuatro veces al año un dato que no cambia es exactamente
            donde aparecen los errores de dedo.
        </p>

        <p class="hint">
            <strong>Si en un período se digita el mismo rubro, lo digitado reemplaza al fijo</strong> — no se
            suma. Si alguien tiene ₡25.000 fijos y este mes se le digitan ₡40.000, cobra ₡40.000, no ₡65.000. El
            rubro fijo sigue vigente y vuelve solo el período siguiente.
        </p>

        <p v-if="!concepts.length" class="flash flash-warning">
            <template v-if="conceptCount">
                Hay {{ conceptCount }} concepto(s) en el catálogo, pero ninguno está marcado como
                <strong>asignable en fijo</strong>. Marcalo en
                <Link :href="route('payroll-settings.index')">Configuración → Conceptos</Link>
                con la casilla de recurrente.
            </template>
            <template v-else>
                Todavía no hay conceptos en el catálogo. Cargá la configuración de planilla primero.
            </template>
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ visible.length }} rubro(s) fijo(s)</span>
                <label class="check inline">
                    <input v-model="showSuspended" type="checkbox">
                    Mostrar suspendidos
                </label>
                <button type="button" class="btn btn-primary" :disabled="!concepts.length" @click="openCreate()">
                    + Asignar rubro fijo
                </button>
            </div>

            <div class="table-scroll freeze-2">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Trabajador</th>
                            <th>Rubro</th>
                            <th>Tipo</th>
                            <th class="right">Monto</th>
                            <th class="right">Cantidad</th>
                            <th>Vigencia</th>
                            <th>Referencia</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in visible" :key="i.id" :class="{ dim: i.status !== 'active' }">
                            <td class="num">{{ i.employee_code }}</td>
                            <td>
                                <Link :href="route('employees.show', i.employee_id)">{{ i.employee_name }}</Link>
                            </td>
                            <td class="small">{{ i.concept_code }} — {{ i.concept_name }}</td>
                            <td class="small">{{ i.concept_type === 'earning' ? 'Ingreso' : 'Deducción' }}</td>
                            <td class="right num">{{ i.amount === null ? '—' : formatMoney(i.amount) }}</td>
                            <td class="right num small">{{ i.quantity ?? '—' }}</td>
                            <td class="num small">{{ i.start_date }} → {{ i.end_date ?? '∞' }}</td>
                            <td class="muted small">{{ i.notes ?? '—' }}</td>
                            <td class="small">{{ i.status === 'active' ? 'Activo' : 'Suspendido' }}</td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEdit(i)">Editar</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="destroy(i)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="10" class="muted empty-row">
                                Sin rubros fijos asignados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating || editing" class="modal-backdrop" @click.self="creating = false; editing = null">
            <form class="modal card" @submit.prevent="creating ? submitCreate() : submitEdit()">
                <h2>{{ creating ? 'Asignar rubro fijo' : 'Editar rubro fijo' }}</h2>

                <div class="field">
                    <label>Trabajador</label>
                    <select v-model="activeForm.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Rubro</label>
                    <select v-model="activeForm.payroll_concept_id" required>
                        <option value="">Elegí</option>
                        <option v-for="c in concepts" :key="c.id" :value="c.id">
                            {{ c.code }} — {{ c.name }}{{ c.type === 'deduction' ? ' (rebajo)' : '' }}
                        </option>
                    </select>
                </div>

                <div v-if="byHours" class="field">
                    <label>Horas por período</label>
                    <input v-model="activeForm.quantity" type="number" step="0.01" min="0" required>
                    <span class="hint small">Se paga al factor {{ selectedConcept?.factor }} sobre la hora ordinaria.</span>
                    <span v-if="activeForm.errors.quantity" class="error">{{ activeForm.errors.quantity }}</span>
                </div>

                <div v-else class="field">
                    <label>Monto por período</label>
                    <input v-model="activeForm.amount" type="number" step="0.01" required>
                    <span v-if="activeForm.errors.amount" class="error">{{ activeForm.errors.amount }}</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Vigente desde</label>
                        <input v-model="activeForm.start_date" type="date" required>
                        <span v-if="activeForm.errors.start_date" class="error">{{ activeForm.errors.start_date }}</span>
                    </div>
                    <div class="field">
                        <label>Vigente hasta</label>
                        <input v-model="activeForm.end_date" type="date">
                        <span class="hint small">Vacío = indefinido.</span>
                        <span v-if="activeForm.errors.end_date" class="error">{{ activeForm.errors.end_date }}</span>
                    </div>
                </div>

                <span class="hint small">
                    Con fechas, un aumento del rubro se carga hoy con vigencia del mes entrante y entra solo, sin
                    tener que acordarse de cambiarlo ese día.
                </span>

                <div class="field">
                    <label>Referencia</label>
                    <input v-model="activeForm.notes" type="text" maxlength="255" placeholder="Acuerdo, adenda, política">
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="activeForm.status" required>
                        <option value="active">Activo</option>
                        <option value="suspended">Suspendido</option>
                    </select>
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
