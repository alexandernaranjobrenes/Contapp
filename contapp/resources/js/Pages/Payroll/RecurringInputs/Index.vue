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

function normalize(data) {
    return {
        ...data,
        amount: data.amount === '' ? null : data.amount,
        quantity: data.quantity === '' ? null : data.quantity,
        end_date: data.end_date === '' ? null : data.end_date,
        notes: data.notes === '' ? null : data.notes,
    };
}

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21).
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.items,
    defaults: () => ({ ...blank }),
    toForm: (item) => Object.fromEntries(Object.keys(blank).map((key) => [key, item[key] ?? blank[key]])),
    store: () => route('recurring-inputs.store'),
    update: (item) => route('recurring-inputs.update', item.id),
    storePayload: normalize,
    updatePayload: normalize,
});

const selectedConcept = computed(
    () => props.concepts.find((c) => c.id === Number(form.payroll_concept_id)) ?? null
);

const byHours = computed(() => selectedConcept.value?.calculation === 'hours');

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Asignar rubro fijo';
    return selected.value ? `${selected.value.concept_code} — ${selected.value.employee_name}` : '';
});

function destroy() {
    const item = selected.value;

    confirmAction({
        title: 'Eliminar rubro fijo',
        message: `El rubro fijo ${item.concept_code} de ${item.employee_name} se elimina; los períodos ya calculados no cambian.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('recurring-inputs.destroy', item.id), { preserveScroll: true }),
    });
}

const showSuspended = ref(false);

const visible = computed(
    () => props.items.filter((i) => showSuspended.value || i.status === 'active')
);

function amountLabel(i) {
    if (i.amount !== null) return formatMoney(i.amount);
    return i.quantity !== null ? `${i.quantity} h` : '—';
}
</script>

<template>
    <Head title="Rubros fijos" />

    <AppLayout title="Rubros fijos">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('payroll-periods.index')" class="btn btn-ghost">Períodos de planilla</Link>
                <Link :href="route('payroll-settings.index')" class="btn btn-ghost">Configuración</Link>
                <button type="button" class="btn btn-primary" :disabled="!concepts.length" @click="openCreate()">
                    <PlusIcon /> Crear nuevo
                </button>
            </div>
            <div class="view-filters">
                <label class="check">
                    <input v-model="showSuspended" type="checkbox">
                    Mostrar suspendidos
                </label>
                <span class="muted small">{{ visible.length }} rubro(s) fijo(s)</span>
            </div>
        </div>

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
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th>Rubro</th>
                            <th class="num">Monto</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="i in visible"
                            :key="i.id"
                            class="clickable-row"
                            :class="{ dim: i.status !== 'active' }"
                            tabindex="0"
                            @click="openDetail(i)"
                            @keydown.enter="openDetail(i)"
                            @keydown.space.prevent="openDetail(i)"
                        >
                            <td><span class="code">{{ i.employee_code }}</span> — {{ i.employee_name }}</td>
                            <td data-label="Rubro" class="small">{{ i.concept_code }} — {{ i.concept_name }}</td>
                            <td data-label="Monto" class="num">{{ amountLabel(i) }}</td>
                            <td data-label="Vigencia" class="code small">{{ i.start_date }} → {{ i.end_date ?? 'indefinido' }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="i.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ i.status === 'active' ? 'Activo' : 'Suspendido' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="5" class="muted empty-row">
                                Sin rubros fijos asignados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'active' ? 'Activo' : 'Suspendido' }}
                </span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Trabajador</dt>
                    <dd>{{ selected.employee_code }} — {{ selected.employee_name }}</dd>
                </div>
                <div>
                    <dt>Rubro</dt>
                    <dd>{{ selected.concept_code }} — {{ selected.concept_name }}</dd>
                </div>
                <div>
                    <dt>Tipo</dt>
                    <dd>{{ selected.concept_type === 'earning' ? 'Ingreso' : 'Deducción' }}</dd>
                </div>
                <div>
                    <dt>Monto</dt>
                    <dd>{{ selected.amount === null ? '—' : formatMoney(selected.amount) }}</dd>
                </div>
                <div>
                    <dt>Cantidad</dt>
                    <dd>{{ selected.quantity ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Vigencia</dt>
                    <dd>{{ selected.start_date }} → {{ selected.end_date ?? 'indefinido' }}</dd>
                </div>
                <div>
                    <dt>Referencia</dt>
                    <dd>{{ selected.notes ?? '—' }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="recurring-form" @submit.prevent="submit">
                <div class="field">
                    <label for="recurring-employee">Trabajador</label>
                    <select id="recurring-employee" v-model="form.employee_id" required>
                        <option value="">Elegí</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                    </select>
                    <span v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</span>
                </div>

                <div class="field">
                    <label for="recurring-concept">Rubro</label>
                    <select id="recurring-concept" v-model="form.payroll_concept_id" required>
                        <option value="">Elegí</option>
                        <option v-for="c in concepts" :key="c.id" :value="c.id">
                            {{ c.code }} — {{ c.name }}{{ c.type === 'deduction' ? ' (rebajo)' : '' }}
                        </option>
                    </select>
                    <span v-if="form.errors.payroll_concept_id" class="error">{{ form.errors.payroll_concept_id }}</span>
                </div>

                <div v-if="byHours" class="field">
                    <label for="recurring-quantity">Horas por período</label>
                    <input id="recurring-quantity" v-model="form.quantity" type="number" step="0.01" min="0" required>
                    <span class="muted small">Se paga al factor {{ selectedConcept?.factor }} sobre la hora ordinaria.</span>
                    <span v-if="form.errors.quantity" class="error">{{ form.errors.quantity }}</span>
                </div>

                <div v-else class="field">
                    <label for="recurring-amount">Monto por período</label>
                    <input id="recurring-amount" v-model="form.amount" type="number" step="0.01" required>
                    <span v-if="form.errors.amount" class="error">{{ form.errors.amount }}</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="recurring-start">Vigente desde</label>
                        <input id="recurring-start" v-model="form.start_date" type="date" required>
                        <span v-if="form.errors.start_date" class="error">{{ form.errors.start_date }}</span>
                    </div>
                    <div class="field">
                        <label for="recurring-end">Vigente hasta (vacío = indefinido)</label>
                        <input id="recurring-end" v-model="form.end_date" type="date">
                        <span v-if="form.errors.end_date" class="error">{{ form.errors.end_date }}</span>
                    </div>
                </div>

                <p class="hint small">
                    Con fechas, un aumento del rubro se carga hoy con vigencia del mes entrante y entra solo, sin
                    tener que acordarse de cambiarlo ese día.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label for="recurring-notes">Referencia</label>
                        <input id="recurring-notes" v-model="form.notes" type="text" maxlength="255" placeholder="Acuerdo, adenda, política">
                    </div>
                    <div class="field">
                        <label for="recurring-status">Estado</label>
                        <select id="recurring-status" v-model="form.status" required>
                            <option value="active">Activo</option>
                            <option value="suspended">Suspendido</option>
                        </select>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link :href="route('employees.show', selected.employee_id)" class="btn btn-ghost"><UserIcon /> Ver trabajador</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="recurring-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Asignar' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.dim { opacity: 0.55; }
</style>
