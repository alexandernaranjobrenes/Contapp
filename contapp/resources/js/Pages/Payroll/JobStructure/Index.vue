<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { formatMoney } from '../../../Utils/money';
import { PencilIcon, PlusIcon } from '@lucide/vue';

const props = defineProps({
    departments: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
});

const page = usePage();

const tab = ref('departamentos');

// Los dos se administran juntos —un puesto pertenece a un departamento— y
// separarlos obligaría a ir y venir para armar la estructura de una empresa
// nueva.
const blanks = {
    department: { code: '', name: '', cost_center_id: '', status: 'active' },
    position: {
        code: '', name: '', department_id: '',
        ccss_occupation_code: '', ccss_occupation_name: '',
        min_salary: '', max_salary: '', description: '', status: 'active',
    },
};

const routes = { department: 'departments', position: 'job-positions' };

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21), para los
// dos catálogos: { kind, id, mode } con mode 'details' | 'edit' | 'create'.
const modal = ref(null);
const rowForm = useForm({ ...blanks.department });

const records = { department: () => props.departments, position: () => props.positions };

const modalRow = computed(() => {
    if (! modal.value || modal.value.id === null) return null;
    return records[modal.value.kind]().find((r) => r.id === modal.value.id) ?? null;
});

function fillForm(kind, row = null) {
    rowForm.clearErrors();
    rowForm.defaults({ ...blanks[kind] });
    rowForm.reset();

    Object.keys(blanks[kind]).forEach((key) => {
        rowForm[key] = row ? (row[key] ?? blanks[kind][key]) : blanks[kind][key];
    });
}

// «Crear nuevo» da de alta en la pestaña que está a la vista.
function openCreate() {
    const kind = tab.value === 'departamentos' ? 'department' : 'position';
    fillForm(kind);
    modal.value = { kind, id: null, mode: 'create' };
}

function openDetail(kind, row) {
    modal.value = { kind, id: row.id, mode: 'details' };
}

function startEdit() {
    fillForm(modal.value.kind, modalRow.value);
    modal.value.mode = 'edit';
}

function cancelForm() {
    if (modal.value.mode === 'create') modal.value = null;
    else modal.value.mode = 'details';
}

function closeModal() {
    modal.value = null;
}

function submitRow() {
    const { kind, mode } = modal.value;

    const transform = (data) => {
        const out = {};
        Object.keys(blanks[kind]).forEach((key) => { out[key] = data[key] === '' ? null : data[key]; });
        // El código y el nombre nunca son nulos; el resto sí puede serlo.
        out.code = data.code;
        out.name = data.name;
        out.status = data.status;

        return out;
    };

    if (mode === 'edit') {
        rowForm.transform(transform).put(route(`${routes[kind]}.update`, modalRow.value.id), {
            preserveScroll: true, onSuccess: () => { modal.value.mode = 'details'; },
        });
    } else {
        rowForm.transform(transform).post(route(`${routes[kind]}.store`), {
            preserveScroll: true, onSuccess: closeModal,
        });
    }
}

function destroyRow() {
    const { kind } = modal.value;
    const row = modalRow.value;

    confirmAction({
        title: kind === 'department' ? 'Eliminar departamento' : 'Eliminar puesto',
        message: `${row.code} — ${row.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route(`${routes[kind]}.destroy`, row.id), { preserveScroll: true }),
    });
}

const modalOpen = computed(() => !! modal.value && (modal.value.mode === 'create' || !! modalRow.value));

const modalTitle = computed(() => {
    if (! modal.value) return '';
    if (modal.value.mode === 'create') return modal.value.kind === 'department' ? 'Nuevo departamento' : 'Nuevo puesto';
    return modalRow.value ? `${modalRow.value.code} — ${modalRow.value.name}` : '';
});

// Un puesto sin código de ocupación de la CCSS hace que ese renglón de la
// planilla se rechace. Se cuenta para poder verlo de un vistazo.
const withoutOccupation = computed(
    () => props.positions.filter((p) => p.status === 'active' && ! p.ccss_occupation_code)
);
</script>

<template>
    <Head title="Departamentos y puestos" />

    <AppLayout title="Departamentos y puestos">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <nav class="tabs" aria-label="Catálogo">
                    <button type="button" class="tab" :class="{ active: tab === 'departamentos' }" :aria-pressed="tab === 'departamentos'" @click="tab = 'departamentos'">
                        Departamentos
                    </button>
                    <button type="button" class="tab" :class="{ active: tab === 'puestos' }" :aria-pressed="tab === 'puestos'" @click="tab = 'puestos'">
                        Puestos
                    </button>
                </nav>
            </div>
        </div>

        <div v-if="page.props.errors?.structure" class="flash flash-error">{{ page.props.errors.structure }}</div>

        <p class="hint">
            Como catálogo y no como texto libre. Con veinte fichas escribiendo a mano, «Contabilidad»,
            «contabilidad» y «Depto. Contabilidad» son tres departamentos distintos, y cualquier reporte
            agrupado sale en pedazos.
        </p>

        <!-- ── Departamentos ────────────────────────────────────────── -->
        <template v-if="tab === 'departamentos'">
            <p class="hint small">
                El centro de costo del departamento es la respuesta por defecto para quien entra a trabajar ahí:
                evita tener que acordarse de ponérselo a cada ficha. {{ departments.length }} departamento(s).
            </p>

            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Centro de costo</th>
                                <th class="num">Trabajadores</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="d in departments"
                                :key="d.id"
                                class="clickable-row"
                                :class="{ dim: d.status !== 'active' }"
                                tabindex="0"
                                @click="openDetail('department', d)"
                                @keydown.enter="openDetail('department', d)"
                                @keydown.space.prevent="openDetail('department', d)"
                            >
                                <td class="code">{{ d.code }}</td>
                                <td data-label="Nombre">{{ d.name }}</td>
                                <td data-label="Centro de costo" class="muted small">{{ d.cost_center ?? '—' }}</td>
                                <td data-label="Trabajadores" class="num">{{ d.employees_count }}</td>
                                <td data-label="Estado">
                                    <span class="badge" :class="d.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                        {{ d.status === 'active' ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!departments.length">
                                <td colspan="5" class="muted empty-row">Sin departamentos.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ── Puestos ──────────────────────────────────────────────── -->
        <template v-if="tab === 'puestos'">
            <p v-if="withoutOccupation.length" class="flash flash-warning">
                {{ withoutOccupation.length }} puesto(s) activos sin código de ocupación de la CCSS:
                {{ withoutOccupation.map((p) => p.code).join(', ') }}. La Caja lo pide en la planilla y rechaza
                el renglón sin él.
            </p>

            <p class="hint small">
                El <strong>código de ocupación de la CCSS</strong> va en el puesto y no en el trabajador:
                todos los que ocupan el mismo puesto reportan la misma ocupación, así se llena una vez.
                El catálogo de la Caja no viene cargado — son cientos de ocupaciones que cambian, y traerlas
                de memoria sería inventarlas. {{ positions.length }} puesto(s).
            </p>

            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Puesto</th>
                                <th>Departamento</th>
                                <th>Ocupación CCSS</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in positions"
                                :key="p.id"
                                class="clickable-row"
                                :class="{ dim: p.status !== 'active' }"
                                tabindex="0"
                                @click="openDetail('position', p)"
                                @keydown.enter="openDetail('position', p)"
                                @keydown.space.prevent="openDetail('position', p)"
                            >
                                <td class="code">{{ p.code }}</td>
                                <td data-label="Puesto">{{ p.name }}</td>
                                <td data-label="Departamento" class="muted small">{{ p.department ?? '—' }}</td>
                                <td data-label="Ocupación CCSS" class="small">
                                    <span v-if="p.ccss_occupation_code" class="code">{{ p.ccss_occupation_code }}</span>
                                    <span v-else class="missing">sin código</span>
                                </td>
                                <td data-label="Estado">
                                    <span class="badge" :class="p.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                        {{ p.status === 'active' ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!positions.length">
                                <td colspan="5" class="muted empty-row">Sin puestos.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <!-- ── Ficha y editor ───────────────────────────────────────── -->
        <DetailModal :open="modalOpen" :wide="modal?.kind === 'position' && modal?.mode !== 'details'" :title="modalTitle" @close="closeModal">
            <template v-if="modalRow && modal.mode === 'details'">
                <dl v-if="modal.kind === 'department'" class="detail-list">
                    <div>
                        <dt>Centro de costo</dt>
                        <dd>{{ modalRow.cost_center ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Trabajadores</dt>
                        <dd>{{ modalRow.employees_count }}</dd>
                    </div>
                    <div>
                        <dt>Estado</dt>
                        <dd>{{ modalRow.status === 'active' ? 'Activo' : 'Inactivo' }}</dd>
                    </div>
                </dl>
                <dl v-else class="detail-list">
                    <div>
                        <dt>Departamento</dt>
                        <dd>{{ modalRow.department ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Ocupación CCSS</dt>
                        <dd>
                            <template v-if="modalRow.ccss_occupation_code">{{ modalRow.ccss_occupation_code }} — {{ modalRow.ccss_occupation_name }}</template>
                            <span v-else class="missing">sin código</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Salario mínimo</dt>
                        <dd>{{ modalRow.min_salary ? formatMoney(modalRow.min_salary) : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Salario máximo</dt>
                        <dd>{{ modalRow.max_salary ? formatMoney(modalRow.max_salary) : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Trabajadores</dt>
                        <dd>{{ modalRow.employees_count }}</dd>
                    </div>
                    <div>
                        <dt>Estado</dt>
                        <dd>{{ modalRow.status === 'active' ? 'Activo' : 'Inactivo' }}</dd>
                    </div>
                    <div v-if="modalRow.description">
                        <dt>Descripción</dt>
                        <dd>{{ modalRow.description }}</dd>
                    </div>
                </dl>
            </template>

            <form v-if="modal && modal.mode !== 'details'" id="structure-form" @submit.prevent="submitRow">
                <div class="field-row">
                    <div class="field">
                        <label for="structure-code">Código</label>
                        <input id="structure-code" v-model="rowForm.code" type="text" maxlength="20" required>
                        <span v-if="rowForm.errors.code" class="error">{{ rowForm.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="structure-name">Nombre</label>
                        <input id="structure-name" v-model="rowForm.name" type="text" maxlength="255" required>
                        <span v-if="rowForm.errors.name" class="error">{{ rowForm.errors.name }}</span>
                    </div>
                </div>

                <template v-if="modal.kind === 'department'">
                    <div class="field">
                        <label for="structure-cc">Centro de costo</label>
                        <select id="structure-cc" v-model="rowForm.cost_center_id">
                            <option value="">Sin centro de costo</option>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <span class="muted small">Se propone a quien entre a trabajar en este departamento.</span>
                    </div>
                </template>

                <template v-else>
                    <div class="field">
                        <label for="structure-department">Departamento</label>
                        <select id="structure-department" v-model="rowForm.department_id">
                            <option value="">Sin departamento</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.code }} — {{ d.name }}</option>
                        </select>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="structure-occ-code">Código de ocupación CCSS</label>
                            <input id="structure-occ-code" v-model="rowForm.ccss_occupation_code" type="text" maxlength="20">
                            <span class="muted small">Sin esto, la Caja rechaza el renglón de ese trabajador.</span>
                        </div>
                        <div class="field">
                            <label for="structure-occ-name">Nombre de la ocupación</label>
                            <input id="structure-occ-name" v-model="rowForm.ccss_occupation_name" type="text" maxlength="255">
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="structure-min">Salario mínimo de referencia</label>
                            <input id="structure-min" v-model="rowForm.min_salary" type="number" step="0.01" min="0">
                        </div>
                        <div class="field">
                            <label for="structure-max">Salario máximo de referencia</label>
                            <input id="structure-max" v-model="rowForm.max_salary" type="number" step="0.01" min="0">
                            <span v-if="rowForm.errors.max_salary" class="error">{{ rowForm.errors.max_salary }}</span>
                        </div>
                    </div>
                    <p class="hint small">
                        El rango es opcional y no bloquea nada: sirve para avisar cuando un salario se sale de lo
                        previsto. Un cero de más en un aumento no lo detecta nadie leyendo la ficha.
                    </p>

                    <div class="field">
                        <label for="structure-description">Descripción</label>
                        <textarea id="structure-description" v-model="rowForm.description" rows="3"></textarea>
                    </div>
                </template>

                <div class="field">
                    <label for="structure-status">Estado</label>
                    <select id="structure-status" v-model="rowForm.status" required>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
            </form>

            <template #actions>
                <template v-if="modalRow && modal.mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroyRow">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="structure-form" class="btn btn-primary" :disabled="rowForm.processing">
                        {{ modal.mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.tabs {
    display: flex;
    gap: 0.25rem;
    border-bottom: 1px solid var(--color-border);
}

.tab {
    min-height: 2.25rem;
    padding: 0.5rem 0.9rem;
    border: none;
    background: none;
    color: var(--color-text-muted);
    font: inherit;
    font-size: 0.84rem;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}

.tab.active { color: var(--color-text); border-bottom-color: var(--color-primary); font-weight: 600; }

table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.dim { opacity: 0.55; }
.missing { color: var(--color-warning); font-size: 0.72rem; font-weight: 600; }
</style>
