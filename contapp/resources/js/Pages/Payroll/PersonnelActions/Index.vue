<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { formatMoney } from '../../../Utils/money';
import { CheckCheckIcon, CheckIcon, PlusIcon, UserIcon } from '@lucide/vue';

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

function approve() {
    router.post(route('personnel-actions.approve', selected.value.id), {}, { preserveScroll: true });
}

function apply() {
    const action = selected.value;

    confirmAction({
        title: 'Aplicar acción de personal',
        message: `La acción se aplica a la ficha de ${action.employee_name}: es el paso que cambia el dato.`,
        confirmLabel: 'Aplicar',
        onConfirm: () => router.post(route('personnel-actions.apply', action.id), {}, { preserveScroll: true }),
    });
}

function cancel() {
    const action = selected.value;

    confirmAction({
        title: 'Anular acción de personal',
        message: `La acción «${action.action_label}» de ${action.employee_name} queda anulada y no se aplica.`,
        confirmLabel: 'Anular',
        danger: true,
        onConfirm: () => router.post(route('personnel-actions.cancel', action.id), {}, { preserveScroll: true }),
    });
}

// Ficha de la acción (CLAUDE.md secc. 20): el antes y el después, quién la
// pidió y quién la aprobó, y los pasos del flujo.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.actions);

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
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <span class="muted small">{{ actions.length }} acción(es)</span>
            </div>
        </div>

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
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Vigencia</th>
                            <th>Trabajador</th>
                            <th>Acción</th>
                            <th class="num">Después</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in actions"
                            :key="a.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(a)"
                            @keydown.enter="openDetail(a)"
                            @keydown.space.prevent="openDetail(a)"
                        >
                            <td class="code">{{ a.effective_date }}</td>
                            <td data-label="Trabajador"><span class="code">{{ a.employee_code }}</span> — {{ a.employee_name }}</td>
                            <td data-label="Acción" class="small">{{ a.action_label }}</td>
                            <td data-label="Después" class="num small">{{ a.new_value ?? '—' }}</td>
                            <td data-label="Estado"><span class="badge" :class="statusClass[a.status]">{{ a.status_label }}</span></td>
                        </tr>
                        <tr v-if="!actions.length">
                            <td colspan="5" class="muted empty-row">Sin acciones registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `${selected.action_label} — ${selected.employee_name}` : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="statusClass[selected.status]">{{ selected.status_label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Rige desde</dt>
                    <dd>{{ selected.effective_date }}</dd>
                </div>
                <div>
                    <dt>Trabajador</dt>
                    <dd>{{ selected.employee_code }} — {{ selected.employee_name }}</dd>
                </div>
                <div>
                    <dt>Antes</dt>
                    <dd>{{ selected.previous_value ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Después</dt>
                    <dd>{{ selected.new_value ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Motivo</dt>
                    <dd>{{ selected.reason ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Solicitó</dt>
                    <dd>{{ selected.requested_by ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Aprobó</dt>
                    <dd>{{ selected.approved_by ?? '—' }}</dd>
                </div>
            </dl>
            <p v-if="selected?.status === 'approved' && !selected.is_due" class="muted small modal-note">
                Aprobada y en espera: rige en el futuro y se podrá aplicar desde su fecha de vigencia.
            </p>

            <template #actions>
                <template v-if="selected">
                    <button
                        v-if="['draft', 'approved'].includes(selected.status)"
                        type="button" class="btn btn-ghost btn-danger-text" @click="cancel"
                    >Anular</button>
                    <Link :href="route('employees.show', selected.employee_id)" class="btn btn-ghost"><UserIcon /> Ver trabajador</Link>
                    <button v-if="selected.status === 'draft'" type="button" class="btn btn-primary" @click="approve"><CheckIcon /> Aprobar</button>
                    <button
                        v-if="selected.status === 'approved' && selected.is_due"
                        type="button" class="btn btn-primary" @click="apply"
                    ><CheckCheckIcon /> Aplicar</button>
                </template>
            </template>
        </DetailModal>

        <DetailModal :open="creating" title="Nueva acción de personal" @close="creating = false">
            <form id="personnel-action-form" @submit.prevent="submit">
                <div class="field">
                    <label for="action-employee">Trabajador</label>
                    <select id="action-employee" v-model="form.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.code }} — {{ e.name }}{{ e.status !== 'active' ? ` (${e.status})` : '' }}
                        </option>
                    </select>
                    <span v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="action-type">Tipo de acción</label>
                        <select id="action-type" v-model="form.action_type" required>
                            <option v-for="(label, value) in types" :key="value" :value="value" :disabled="value === 'hire'">
                                {{ label }}
                            </option>
                        </select>
                        <span class="muted small">La contratación se registra sola al crear la ficha.</span>
                    </div>
                    <div class="field">
                        <label for="action-date">Rige desde</label>
                        <input id="action-date" v-model="form.effective_date" type="date" required>
                        <span class="muted small">No es la fecha en que se digita.</span>
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
                    <label for="action-new-value">Motivo de la salida</label>
                    <select id="action-new-value" v-model="form.new_value" required>
                        <option value="">Elegí</option>
                        <option value="renuncia">Renuncia</option>
                        <option value="despido_con_causa">Despido con responsabilidad del trabajador</option>
                        <option value="despido_sin_causa">Despido sin justa causa</option>
                        <option value="vencimiento">Vencimiento del plazo</option>
                        <option value="mutuo_acuerdo">Mutuo acuerdo</option>
                        <option value="fallecimiento">Fallecimiento</option>
                    </select>
                    <span class="muted small">
                        Decide si corresponden preaviso y cesantía. Aplicar esta acción da de baja al trabajador.
                    </span>
                </div>

                <div v-else-if="isCostCenter" class="field">
                    <label for="action-new-value">Centro de costo nuevo</label>
                    <select id="action-new-value" v-model="form.new_value" required>
                        <option value="">Elegí</option>
                        <option v-for="c in costCenters" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}</option>
                    </select>
                </div>

                <div v-else-if="targetField === 'journey_type'" class="field">
                    <label for="action-new-value">Jornada nueva</label>
                    <select id="action-new-value" v-model="form.new_value" required>
                        <option value="">Elegí</option>
                        <option value="diurna">Diurna (8 h ordinarias)</option>
                        <option value="mixta">Mixta (7 h ordinarias)</option>
                        <option value="nocturna">Nocturna (6 h ordinarias)</option>
                    </select>
                    <span class="muted small">Cambia a partir de qué hora una hora es extra.</span>
                </div>

                <div v-else-if="targetField" class="field">
                    <label for="action-new-value">{{ targetField === 'base_salary' ? 'Salario nuevo' : 'Puesto nuevo' }}</label>
                    <input
                        id="action-new-value"
                        v-model="form.new_value"
                        :type="targetField === 'base_salary' ? 'number' : 'text'"
                        :step="targetField === 'base_salary' ? '0.01' : undefined"
                        required
                    >
                    <span v-if="form.errors.new_value" class="error">{{ form.errors.new_value }}</span>
                </div>

                <div class="field">
                    <label for="action-reason">Motivo</label>
                    <textarea id="action-reason" v-model="form.reason" rows="2" placeholder="Por qué se toma esta decisión"></textarea>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                <button type="submit" form="personnel-action-form" class="btn btn-primary" :disabled="form.processing">Registrar en borrador</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; white-space: nowrap; }
.modal-note { margin: 0.75rem 0 0; }

.current-box {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 0.6rem;
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.7rem;
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}

.current-box strong { font-variant-numeric: tabular-nums; }
</style>
