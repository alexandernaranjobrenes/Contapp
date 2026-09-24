<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    actions: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
    types: { type: Object, required: true },
    statuses: { type: Object, required: true },
    fieldByType: { type: Object, required: true },
});

const page = usePage();

const creating = ref(false);
const form = useForm({
    employee_id: '',
    action_type: 'salary_change',
    effective_date: '',
    new_value: '',
    reason: '',
    notes: '',
});

const selectedEmployee = computed(
    () => props.employees.find((e) => e.id === Number(form.employee_id)) ?? null
);

// El campo que la acción va a tocar, y su valor actual. Enseñar el "antes"
// mientras se digita el "después" es lo que evita el aumento con un cero de
// más.
const targetField = computed(() => props.fieldByType[form.action_type] ?? null);

const currentValue = computed(() => {
    if (! selectedEmployee.value || ! targetField.value) return null;

    return selectedEmployee.value[targetField.value];
});

const isTermination = computed(() => form.action_type === 'termination');
const isCostCenter = computed(() => targetField.value === 'cost_center_id');

function openCreate() {
    form.reset();
    creating.value = true;
}

function submit() {
    form.transform((data) => ({
        ...data,
        new_value: data.new_value === '' ? null : String(data.new_value),
        reason: data.reason === '' ? null : data.reason,
        notes: data.notes === '' ? null : data.notes,
    })).post(route('personnel-actions.store'), {
        onSuccess: () => (creating.value = false), preserveScroll: true,
    });
}

function approve(action) {
    router.post(route('personnel-actions.approve', action.id), {}, { preserveScroll: true });
}

function apply(action) {
    if (! confirm(`¿Aplicar esta acción a la ficha de ${action.employee_name}? Es el paso que cambia el dato.`)) return;

    router.post(route('personnel-actions.apply', action.id), {}, { preserveScroll: true });
}

function cancel(action) {
    if (! confirm('¿Anular esta acción?')) return;

    router.post(route('personnel-actions.cancel', action.id), {}, { preserveScroll: true });
}

const statusClass = {
    draft: 'badge-neutral',
    approved: 'badge-warning',
    applied: 'badge-success',
    cancelled: 'badge-danger',
};

const pending = computed(() => props.actions.filter((a) => ['draft', 'approved'].includes(a.status)));
</script>

<template>
    <Head title="Acciones de personal" />

    <AppLayout title="Acciones de personal">
        <template #actions>
            <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.action" class="flash flash-error">{{ page.props.errors.action }}</div>

        <p class="hint">
            Un aumento no es una edición de la ficha: es un hecho con vigencia, un antes y un después, un motivo
            y un responsable. El flujo es <strong>borrador → aprobada → aplicada</strong>, y solo el último paso
            toca la ficha — así un aumento acordado hoy con vigencia del mes entrante queda aprobado ahora y
            entra cuando corresponde.
        </p>

        <p v-if="pending.length" class="flash flash-warning">
            {{ pending.length }} acción(es) sin aplicar. Mientras no se apliquen, la ficha sigue diciendo lo de antes.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ actions.length }} acción(es)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva acción</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Vigencia</th>
                            <th>Trabajador</th>
                            <th>Acción</th>
                            <th class="right">Antes</th>
                            <th class="right">Después</th>
                            <th>Motivo</th>
                            <th>Solicitó</th>
                            <th>Aprobó</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in actions" :key="a.id">
                            <td class="num small">{{ a.effective_date }}</td>
                            <td>
                                <Link :href="route('employees.show', a.employee_id)">{{ a.employee_code }}</Link>
                                <span class="muted small"> {{ a.employee_name }}</span>
                            </td>
                            <td class="small">{{ a.action_label }}</td>
                            <td class="right num small muted">{{ a.previous_value ?? '—' }}</td>
                            <td class="right num small">{{ a.new_value ?? '—' }}</td>
                            <td class="muted small">{{ a.reason ?? '—' }}</td>
                            <td class="muted small">{{ a.requested_by ?? '—' }}</td>
                            <td class="muted small">{{ a.approved_by ?? '—' }}</td>
                            <td><span class="badge" :class="statusClass[a.status]">{{ a.status_label }}</span></td>
                            <td class="row-actions">
                                <button v-if="a.status === 'draft'" type="button" class="btn btn-ghost btn-sm" @click="approve(a)">Aprobar</button>
                                <button
                                    v-if="a.status === 'approved' && a.is_due"
                                    type="button" class="btn btn-ghost btn-sm" @click="apply(a)"
                                >Aplicar</button>
                                <span v-else-if="a.status === 'approved'" class="muted small" title="Rige en el futuro">en espera</span>
                                <button
                                    v-if="['draft', 'approved'].includes(a.status)"
                                    type="button" class="btn btn-ghost btn-sm" @click="cancel(a)"
                                >Anular</button>
                            </td>
                        </tr>
                        <tr v-if="!actions.length">
                            <td colspan="10" class="muted empty-row">Sin acciones registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal card" @submit.prevent="submit">
                <h2>Nueva acción de personal</h2>

                <div class="field">
                    <label>Trabajador</label>
                    <select v-model="form.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.code }} — {{ e.name }}{{ e.status !== 'active' ? ` (${e.status})` : '' }}
                        </option>
                    </select>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Tipo de acción</label>
                        <select v-model="form.action_type" required>
                            <option v-for="(label, value) in types" :key="value" :value="value" :disabled="value === 'hire'">
                                {{ label }}
                            </option>
                        </select>
                        <span class="hint small">La contratación se registra sola al crear la ficha.</span>
                    </div>
                    <div class="field">
                        <label>Rige desde</label>
                        <input v-model="form.effective_date" type="date" required>
                        <span class="hint small">No es la fecha en que se digita.</span>
                        <span v-if="form.errors.effective_date" class="error">{{ form.errors.effective_date }}</span>
                    </div>
                </div>

                <div v-if="currentValue !== null" class="current-box">
                    <span class="muted small">Valor actual en la ficha</span>
                    <strong>
                        {{ targetField === 'base_salary' ? formatMoney(currentValue) : (currentValue ?? '—') }}
                    </strong>
                </div>

                <div v-if="isTermination" class="field">
                    <label>Motivo de la salida</label>
                    <select v-model="form.new_value" required>
                        <option value="">Elegí</option>
                        <option value="renuncia">Renuncia</option>
                        <option value="despido_con_causa">Despido con responsabilidad del trabajador</option>
                        <option value="despido_sin_causa">Despido sin justa causa</option>
                        <option value="vencimiento">Vencimiento del plazo</option>
                        <option value="mutuo_acuerdo">Mutuo acuerdo</option>
                        <option value="fallecimiento">Fallecimiento</option>
                    </select>
                    <span class="hint small">
                        Decide si corresponden preaviso y cesantía. Aplicar esta acción da de baja al trabajador.
                    </span>
                </div>

                <div v-else-if="isCostCenter" class="field">
                    <label>Centro de costo nuevo</label>
                    <select v-model="form.new_value" required>
                        <option value="">Elegí</option>
                        <option v-for="c in costCenters" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}</option>
                    </select>
                </div>

                <div v-else-if="targetField === 'journey_type'" class="field">
                    <label>Jornada nueva</label>
                    <select v-model="form.new_value" required>
                        <option value="">Elegí</option>
                        <option value="diurna">Diurna (8 h ordinarias)</option>
                        <option value="mixta">Mixta (7 h ordinarias)</option>
                        <option value="nocturna">Nocturna (6 h ordinarias)</option>
                    </select>
                    <span class="hint small">Cambia a partir de qué hora una hora es extra.</span>
                </div>

                <div v-else-if="targetField" class="field">
                    <label>{{ targetField === 'base_salary' ? 'Salario nuevo' : 'Puesto nuevo' }}</label>
                    <input
                        v-model="form.new_value"
                        :type="targetField === 'base_salary' ? 'number' : 'text'"
                        :step="targetField === 'base_salary' ? '0.01' : undefined"
                        required
                    >
                    <span v-if="form.errors.new_value" class="error">{{ form.errors.new_value }}</span>
                </div>

                <div class="field">
                    <label>Motivo</label>
                    <textarea v-model="form.reason" rows="2" placeholder="Por qué se toma esta decisión"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Registrar en borrador</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.current-box {
    display: flex;
    align-items: baseline;
    gap: 0.6rem;
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.7rem;
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}

.current-box strong { font-variant-numeric: tabular-nums; }
.error { color: var(--color-danger); font-size: 0.76rem; }
</style>
