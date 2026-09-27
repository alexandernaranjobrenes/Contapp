<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { formatMoney } from '../../../Utils/money';

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

const editor = ref(null);
const rowForm = useForm({ ...blanks.department });

function openEditor(kind, row = null) {
    rowForm.clearErrors();
    rowForm.defaults({ ...blanks[kind] });
    rowForm.reset();

    Object.keys(blanks[kind]).forEach((key) => {
        rowForm[key] = row ? (row[key] ?? blanks[kind][key]) : blanks[kind][key];
    });

    editor.value = { kind, row };
}

function submitRow() {
    const { kind, row } = editor.value;

    const transform = (data) => {
        const out = {};
        Object.keys(blanks[kind]).forEach((key) => { out[key] = data[key] === '' ? null : data[key]; });
        // El código y el nombre nunca son nulos; el resto sí puede serlo.
        out.code = data.code;
        out.name = data.name;
        out.status = data.status;

        return out;
    };

    const done = { onSuccess: () => (editor.value = null), preserveScroll: true };

    if (row) {
        rowForm.transform(transform).put(route(`${routes[kind]}.update`, row.id), done);
    } else {
        rowForm.transform(transform).post(route(`${routes[kind]}.store`), done);
    }
}

function destroyRow(kind, row) {
    if (! confirm(`¿Eliminar ${row.code} — ${row.name}?`)) return;

    router.delete(route(`${routes[kind]}.destroy`, row.id), { preserveScroll: true });
}

// Un puesto sin código de ocupación de la CCSS hace que ese renglón de la
// planilla se rechace. Se cuenta para poder verlo de un vistazo.
const withoutOccupation = computed(
    () => props.positions.filter((p) => p.status === 'active' && ! p.ccss_occupation_code)
);
</script>

<template>
    <Head title="Departamentos y puestos" />

    <AppLayout title="Departamentos y puestos">
        <template #actions>
            <Link :href="route('employees.index')" class="btn btn-ghost">Empleados</Link>
        </template>

        <div v-if="page.props.errors?.structure" class="flash flash-error">{{ page.props.errors.structure }}</div>

        <p class="hint">
            Como catálogo y no como texto libre. Con veinte fichas escribiendo a mano, «Contabilidad»,
            «contabilidad» y «Depto. Contabilidad» son tres departamentos distintos, y cualquier reporte
            agrupado sale en pedazos.
        </p>

        <nav class="tabs">
            <button type="button" class="tab" :class="{ active: tab === 'departamentos' }" @click="tab = 'departamentos'">
                Departamentos
            </button>
            <button type="button" class="tab" :class="{ active: tab === 'puestos' }" @click="tab = 'puestos'">
                Puestos
            </button>
        </nav>

        <!-- ── Departamentos ────────────────────────────────────────── -->
        <div v-if="tab === 'departamentos'" class="card">
            <div class="card-header">
                <span class="muted">{{ departments.length }} departamento(s)</span>
                <button type="button" class="btn btn-primary" @click="openEditor('department')">+ Nuevo departamento</button>
            </div>

            <p class="hint small">
                El centro de costo del departamento es la respuesta por defecto para quien entra a trabajar ahí:
                evita tener que acordarse de ponérselo a cada ficha.
            </p>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Centro de costo</th>
                            <th class="right">Trabajadores</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in departments" :key="d.id" :class="{ dim: d.status !== 'active' }">
                            <td class="num">{{ d.code }}</td>
                            <td>{{ d.name }}</td>
                            <td class="muted small">{{ d.cost_center ?? '—' }}</td>
                            <td class="right num">{{ d.employees_count }}</td>
                            <td class="small">{{ d.status === 'active' ? 'Activo' : 'Inactivo' }}</td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEditor('department', d)">Editar</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="destroyRow('department', d)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!departments.length">
                            <td colspan="6" class="muted empty-row">Sin departamentos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Puestos ──────────────────────────────────────────────── -->
        <div v-if="tab === 'puestos'">
            <p v-if="withoutOccupation.length" class="flash flash-warning">
                {{ withoutOccupation.length }} puesto(s) activos sin código de ocupación de la CCSS:
                {{ withoutOccupation.map((p) => p.code).join(', ') }}. La Caja lo pide en la planilla y rechaza
                el renglón sin él.
            </p>

            <div class="card">
                <div class="card-header">
                    <span class="muted">{{ positions.length }} puesto(s)</span>
                    <button type="button" class="btn btn-primary" @click="openEditor('position')">+ Nuevo puesto</button>
                </div>

                <p class="hint small">
                    El <strong>código de ocupación de la CCSS</strong> va en el puesto y no en el trabajador:
                    todos los que ocupan el mismo puesto reportan la misma ocupación, así se llena una vez.
                    El catálogo de la Caja no viene cargado — son cientos de ocupaciones que cambian, y traerlas
                    de memoria sería inventarlas.
                </p>

                <div class="table-scroll freeze-2">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Puesto</th>
                                <th>Departamento</th>
                                <th>Ocupación CCSS</th>
                                <th class="right">Salario mín.</th>
                                <th class="right">Salario máx.</th>
                                <th class="right">Trabajadores</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in positions" :key="p.id" :class="{ dim: p.status !== 'active' }">
                                <td class="num">{{ p.code }}</td>
                                <td>{{ p.name }}</td>
                                <td class="muted small">{{ p.department ?? '—' }}</td>
                                <td class="small">
                                    <template v-if="p.ccss_occupation_code">
                                        <span class="num">{{ p.ccss_occupation_code }}</span>
                                        <span class="muted"> {{ p.ccss_occupation_name }}</span>
                                    </template>
                                    <span v-else class="missing">sin código</span>
                                </td>
                                <td class="right num small">{{ p.min_salary ? formatMoney(p.min_salary) : '—' }}</td>
                                <td class="right num small">{{ p.max_salary ? formatMoney(p.max_salary) : '—' }}</td>
                                <td class="right num">{{ p.employees_count }}</td>
                                <td class="small">{{ p.status === 'active' ? 'Activo' : 'Inactivo' }}</td>
                                <td class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="openEditor('position', p)">Editar</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="destroyRow('position', p)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="!positions.length">
                                <td colspan="9" class="muted empty-row">Sin puestos.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Editor ───────────────────────────────────────────────── -->
        <div v-if="editor" class="modal-backdrop" @click.self="editor = null">
            <form class="modal card" @submit.prevent="submitRow">
                <h2>
                    {{ editor.row ? 'Editar' : 'Nuevo' }}
                    {{ editor.kind === 'department' ? 'departamento' : 'puesto' }}
                </h2>

                <div class="field-row">
                    <div class="field">
                        <label>Código</label>
                        <input v-model="rowForm.code" type="text" maxlength="20" required>
                        <span v-if="rowForm.errors.code" class="error">{{ rowForm.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label>Nombre</label>
                        <input v-model="rowForm.name" type="text" maxlength="255" required>
                    </div>
                </div>

                <template v-if="editor.kind === 'department'">
                    <div class="field">
                        <label>Centro de costo</label>
                        <select v-model="rowForm.cost_center_id">
                            <option value="">Sin centro de costo</option>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <span class="hint small">Se propone a quien entre a trabajar en este departamento.</span>
                    </div>
                </template>

                <template v-else>
                    <div class="field">
                        <label>Departamento</label>
                        <select v-model="rowForm.department_id">
                            <option value="">Sin departamento</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.code }} — {{ d.name }}</option>
                        </select>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Código de ocupación CCSS</label>
                            <input v-model="rowForm.ccss_occupation_code" type="text" maxlength="20">
                            <span class="hint small">Sin esto, la Caja rechaza el renglón de ese trabajador.</span>
                        </div>
                        <div class="field">
                            <label>Nombre de la ocupación</label>
                            <input v-model="rowForm.ccss_occupation_name" type="text" maxlength="255">
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Salario mínimo de referencia</label>
                            <input v-model="rowForm.min_salary" type="number" step="0.01" min="0">
                        </div>
                        <div class="field">
                            <label>Salario máximo de referencia</label>
                            <input v-model="rowForm.max_salary" type="number" step="0.01" min="0">
                            <span v-if="rowForm.errors.max_salary" class="error">{{ rowForm.errors.max_salary }}</span>
                        </div>
                    </div>
                    <span class="hint small">
                        El rango es opcional y no bloquea nada: sirve para avisar cuando un salario se sale de lo
                        previsto. Un cero de más en un aumento no lo detecta nadie leyendo la ficha.
                    </span>

                    <div class="field">
                        <label>Descripción</label>
                        <textarea v-model="rowForm.description" rows="3"></textarea>
                    </div>
                </template>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="rowForm.status" required>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="editor = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="rowForm.processing">Guardar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.tabs {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--color-border);
}

.tab {
    padding: 0.5rem 0.9rem;
    border: none;
    background: none;
    color: var(--color-text-muted);
    font-size: 0.84rem;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}

.tab.active { color: var(--color-text); border-bottom-color: var(--color-primary); font-weight: 600; }

.card > .hint { padding: 0 1.1rem; }
.dim { opacity: 0.55; }
.missing { color: #a04000; font-size: 0.72rem; }
.error { color: var(--color-danger); font-size: 0.76rem; }
</style>
