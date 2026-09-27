<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    report: { type: Object, required: true },
    filters: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
    availableColumns: { type: Array, default: null },
    selectedColumns: { type: Array, default: null },
    columns: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    notes: { type: Array, default: () => [] },
    rowCount: { type: Number, default: 0 },
});

// Una copia local para que escribir en un filtro no dispare una consulta por
// tecla: se aplica al soltar el control o con el botón.
const draft = ref({ ...props.values });

const picked = ref([...(props.selectedColumns ?? [])]);
const pickingColumns = ref(false);

function queryParams() {
    const params = {};

    for (const [key, value] of Object.entries(draft.value)) {
        if (value === null || value === '' || value === false) continue;
        params[key] = value === true ? 1 : value;
    }

    // Las columnas viajan en la URL igual que los filtros: así el enlace que
    // alguien comparte —o la exportación— trae exactamente lo que estaba
    // viendo, columnas incluidas.
    if (props.availableColumns && picked.value.length) {
        params.columns = picked.value.join(',');
    }

    return params;
}

function apply() {
    router.get(route('payroll-reports.show', props.report.code), queryParams(), {
        preserveState: true, replace: true, preserveScroll: true,
    });
}

function clearFilters() {
    draft.value = {};
    router.get(route('payroll-reports.show', props.report.code), {}, { preserveState: false });
}

// La exportación arrastra los mismos filtros y las mismas columnas que la
// pantalla: un archivo que no coincide con lo que se está viendo es peor que
// no tenerlo.
function outputUrl(routeName) {
    return route(routeName, { report: props.report.code, ...queryParams() });
}

function toggleColumn(key) {
    picked.value = picked.value.includes(key)
        ? picked.value.filter((k) => k !== key)
        : [...picked.value, key];
}

function allColumns() {
    picked.value = props.availableColumns.map((c) => c.key);
}

const hasTotals = computed(() => Object.keys(props.totals).length > 0);
</script>

<template>
    <Head :title="report.label" />

    <AppLayout :title="report.label">
        <template #actions>
            <a :href="outputUrl('payroll-reports.export')" class="btn btn-ghost">Excel</a>
            <a :href="outputUrl('payroll-reports.export-pdf')" class="btn btn-ghost">PDF</a>
            <!--
                Imprimir abre el MISMO PDF en el navegador: un reporte de
                planilla que se imprime se firma y se archiva, y tiene que
                salir con el encabezado de la empresa y los filtros con que se
                corrió. Imprimir la pantalla daría una hoja anónima.
            -->
            <a :href="outputUrl('payroll-reports.print')" target="_blank" rel="noopener" class="btn btn-ghost">
                Imprimir
            </a>
            <Link :href="route('payroll-reports.index')" class="btn btn-ghost">Todos los reportes</Link>
        </template>

        <p class="decision">
            <span class="decision-label">Sirve para decidir:</span> {{ report.decision }}
        </p>

        <form class="card filters" @submit.prevent="apply">
            <div class="filter-grid">
                <div v-for="f in filters" :key="f.key" class="field">
                    <label :for="'f-' + f.key">{{ f.label }}</label>

                    <select v-if="f.type === 'select'" :id="'f-' + f.key" v-model="draft[f.key]" @change="apply">
                        <option value="">Todos</option>
                        <option v-for="(label, value) in f.options" :key="value" :value="value">{{ label }}</option>
                    </select>

                    <input
                        v-else-if="f.type === 'date'"
                        :id="'f-' + f.key" v-model="draft[f.key]" type="date" @change="apply"
                    >

                    <label v-else-if="f.type === 'boolean'" class="check">
                        <input v-model="draft[f.key]" type="checkbox" @change="apply">
                        <span>{{ f.label }}</span>
                    </label>

                    <input v-else :id="'f-' + f.key" v-model="draft[f.key]" type="text" @keyup.enter="apply">

                    <span v-if="f.hint" class="hint small">{{ f.hint }}</span>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Consultar</button>
                <button type="button" class="btn btn-ghost" @click="clearFilters">Limpiar filtros</button>
                <button
                    v-if="availableColumns"
                    type="button" class="btn btn-ghost"
                    @click="pickingColumns = ! pickingColumns"
                >
                    Columnas ({{ picked.length }} de {{ availableColumns.length }})
                </button>
                <span class="muted small">{{ rowCount }} fila(s)</span>
            </div>

            <div v-if="availableColumns && pickingColumns" class="columns-panel">
                <p class="hint small">
                    Escogé las columnas que necesitás. Salen en el orden del reporte —no en el que se marcan—
                    para que dos exportaciones del mismo reporte se puedan comparar columna a columna.
                </p>

                <div class="columns-grid">
                    <label v-for="c in availableColumns" :key="c.key" class="check">
                        <input
                            type="checkbox"
                            :checked="picked.includes(c.key)"
                            @change="toggleColumn(c.key)"
                        >
                        <span>{{ c.label }}</span>
                    </label>
                </div>

                <div class="filter-actions">
                    <button type="button" class="btn btn-primary btn-sm" @click="apply">Aplicar columnas</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="allColumns">Marcar todas</button>
                </div>
            </div>
        </form>

        <ul v-if="notes.length" class="notes">
            <li v-for="(n, i) in notes" :key="i">{{ n }}</li>
        </ul>

        <div class="card">
            <div class="table-scroll" :class="{ 'freeze-2': report.frozen_columns === 2 }">
                <table>
                    <thead>
                        <tr>
                            <th v-for="c in columns" :key="c.key" :class="{ right: c.numeric }">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="i">
                            <td
                                v-for="c in columns"
                                :key="c.key"
                                :class="{ right: c.numeric, signal: c.key === 'missing' && row[c.key] !== '—' }"
                            >{{ row[c.key] }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="columns.length" class="muted empty-row">
                                No hay datos para los filtros seleccionados.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="hasTotals && rows.length">
                        <tr>
                            <td
                                v-for="(c, index) in columns"
                                :key="c.key"
                                :class="{ right: c.numeric }"
                            >
                                <template v-if="totals[c.key] !== undefined">{{ totals[c.key] }}</template>
                                <template v-else-if="index === 0">Total</template>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.decision { font-size: 0.85rem; margin: 0 0 0.75rem; max-width: 80ch; }
.decision-label { font-weight: 600; color: var(--color-text-muted); }

.filters { padding: 0.9rem 1.1rem; margin-bottom: 0.75rem; }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.2rem; }
.field > label { font-size: 0.76rem; font-weight: 600; color: var(--color-text-muted); }
.check { display: flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; font-weight: 400; }
.check span { color: var(--color-text); }
.filter-actions { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.8rem; flex-wrap: wrap; }

.columns-panel {
    margin-top: 0.9rem;
    padding-top: 0.8rem;
    border-top: 1px solid var(--color-border);
}

.columns-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.25rem 0.75rem;
}

.notes { margin: 0 0 0.75rem 1.1rem; padding: 0; font-size: 0.78rem; color: var(--color-text-muted); }
.notes li { margin-bottom: 0.15rem; }

.table-scroll.freeze-2 { --freeze-1-width: 9rem; }

.right { text-align: right; }
.signal { color: #a04000; font-weight: 600; }
.empty-row { text-align: center; padding: 1.5rem; }
.small { font-size: 0.74rem; }
</style>
