<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    report: { type: Object, required: true },
    filters: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
    columns: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    notes: { type: Array, default: () => [] },
    rowCount: { type: Number, default: 0 },
});

// Una copia local para que escribir en un filtro no dispare una consulta por
// tecla: se aplica al soltar el control o con el botón.
const draft = ref({ ...props.values });

function queryParams() {
    const params = {};

    for (const [key, value] of Object.entries(draft.value)) {
        if (value === null || value === '' || value === false) continue;
        params[key] = value === true ? 1 : value;
    }

    return params;
}

function apply() {
    router.get(route('inventory-reports.show', props.report.code), queryParams(), {
        preserveState: true, replace: true, preserveScroll: true,
    });
}

function clearFilters() {
    draft.value = {};
    router.get(route('inventory-reports.show', props.report.code), {}, { preserveState: false });
}

// La exportación arrastra los mismos filtros que la pantalla: un archivo que
// no coincide con lo que se está viendo es peor que no tenerlo.
function exportUrl(routeName) {
    return route(routeName, { report: props.report.code, ...queryParams() });
}

const hasTotals = computed(() => Object.keys(props.totals).length > 0);

// La impresión usa la misma pantalla con CSS de impresión, no una vista
// aparte: así no hay dos maquetas que se puedan desincronizar.
function print() {
    window.print();
}
</script>

<template>
    <Head :title="report.label" />

    <AppLayout :title="report.label">
        <template #actions>
            <a :href="exportUrl('inventory-reports.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('inventory-reports.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <button type="button" class="btn btn-ghost" @click="print">Imprimir</button>
            <Link :href="route('inventory-reports.index')" class="btn btn-ghost">Todos los reportes</Link>
        </template>

        <p class="decision no-print">
            <span class="decision-label">Sirve para decidir:</span> {{ report.decision }}
        </p>

        <form class="card filters no-print" @submit.prevent="apply">
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
                <span class="muted small">{{ rowCount }} fila(s)</span>
            </div>
        </form>

        <!-- En impresión el encabezado de la empresa lo pone el PDF; acá
             basta con el título y los parámetros para que la hoja impresa
             no salga anónima. -->
        <div class="print-only print-header">
            <strong>{{ report.label }}</strong>
        </div>

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
                                :class="{
                                    right: c.numeric,
                                    signal: (c.key === 'signal' || c.key === 'flag') && row[c.key] !== '—',
                                }"
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
.filter-actions { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.8rem; }

.notes { margin: 0 0 0.75rem 1.1rem; padding: 0; font-size: 0.78rem; color: var(--color-text-muted); }
.notes li { margin-bottom: 0.15rem; }

/* Ancho de la primera columna congelada: lo usa .freeze-2 para saber
   dónde empieza la segunda. */
.table-scroll.freeze-2 { --freeze-1-width: 9rem; }

.right { text-align: right; }
.signal { color: #a04000; font-weight: 600; }
.empty-row { text-align: center; padding: 1.5rem; }
.small { font-size: 0.74rem; }

.print-only { display: none; }

@media print {
    /* Se imprime la tabla y nada más: filtros, botones y navegación no
       aportan en papel y se comen media hoja. */
    .no-print { display: none !important; }
    .print-only { display: block; }
    .print-header { margin-bottom: 0.5rem; font-size: 12pt; }

    .card { border: none; box-shadow: none; padding: 0; }
    .table-scroll { overflow: visible; }
    table { font-size: 7pt; width: 100%; }
    th, td { padding: 2px 4px; border: 1px solid #ccc; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>
