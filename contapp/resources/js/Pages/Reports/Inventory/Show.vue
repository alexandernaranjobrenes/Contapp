<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeftIcon, DownloadIcon, PrinterIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';

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

// Las columnas las define cada reporte en el servidor y algunos traen una
// docena. En pantalla se ven las primeras VISIBLE —la identidad de la fila y
// sus cifras principales—, así la tabla cabe sin desplazarse de lado
// (CLAUDE.md secc. 20); el resto está en la ficha de la fila. Al imprimir, y
// en el XLSX y el PDF, salen todas.
const VISIBLE = 6;
const hiddenColumns = computed(() => props.columns.slice(VISIBLE));
const hasHidden = computed(() => hiddenColumns.value.length > 0);

const selectedIndex = ref(null);
const selectedRow = computed(() => (selectedIndex.value === null ? null : props.rows[selectedIndex.value] ?? null));

function openRow(index) {
    if (hasHidden.value) selectedIndex.value = index;
}
</script>

<template>
    <Head :title="report.label" />

    <AppLayout :title="report.label">
        <div class="view-toolbar">
            <Link :href="route('inventory-reports.index')" class="btn btn-ghost"><ArrowLeftIcon /> Todos los reportes</Link>
            <div class="view-actions">
                <a :href="exportUrl('inventory-reports.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('inventory-reports.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <button type="button" class="btn btn-ghost" @click="print"><PrinterIcon /> Imprimir</button>
            </div>
        </div>

        <p class="decision no-print">
            <span class="decision-label">Sirve para decidir:</span> {{ report.decision }}
        </p>

        <!-- Filtros del reporte, arriba de la tabla (CLAUDE.md secc. 24). -->
        <form class="view-toolbar no-print" @submit.prevent="apply">
            <div class="view-filters">
                <template v-for="f in filters" :key="f.key">
                    <label v-if="f.type === 'boolean'" class="check" :title="f.hint ?? null">
                        <input v-model="draft[f.key]" type="checkbox" @change="apply">
                        {{ f.label }}
                    </label>

                    <label v-else class="filter-field" :title="f.hint ?? null">
                        <span>{{ f.label }}</span>
                        <select v-if="f.type === 'select'" v-model="draft[f.key]" @change="apply">
                            <option value="">Todos</option>
                            <option v-for="(label, value) in f.options" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <input v-else-if="f.type === 'date'" v-model="draft[f.key]" type="date" @change="apply">
                        <input v-else v-model="draft[f.key]" type="text" @keyup.enter="apply">
                    </label>
                </template>

                <button type="submit" class="btn btn-primary">Consultar</button>
                <button type="button" class="btn btn-ghost" @click="clearFilters">Limpiar filtros</button>
                <span class="muted small row-count">{{ rowCount }} fila(s)</span>
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
            <div class="table-responsive table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th
                                v-for="(c, index) in columns"
                                :key="c.key"
                                :class="{ num: c.numeric, 'col-extra': index >= VISIBLE }"
                            >{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, i) in rows"
                            :key="i"
                            :class="{ 'clickable-row': hasHidden }"
                            :tabindex="hasHidden ? 0 : null"
                            @click="openRow(i)"
                            @keydown.enter="openRow(i)"
                            @keydown.space.prevent="hasHidden && openRow(i)"
                        >
                            <td
                                v-for="(c, index) in columns"
                                :key="c.key"
                                :data-label="index === 0 ? null : c.label"
                                :class="{
                                    num: c.numeric,
                                    'col-extra': index >= VISIBLE,
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
                                :data-label="index === 0 ? null : c.label"
                                :class="{ num: c.numeric, 'col-extra': index >= VISIBLE }"
                            >
                                <template v-if="totals[c.key] !== undefined">{{ totals[c.key] }}</template>
                                <template v-else-if="index === 0">Total</template>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p v-if="hasHidden && rows.length" class="hint more-columns no-print">
            Tocá una fila para ver sus {{ columns.length }} columnas; el XLSX, el PDF y la impresión las traen todas.
        </p>

        <DetailModal :open="!!selectedRow" :title="selectedRow ? String(selectedRow[columns[0]?.key] ?? '') : ''" @close="selectedIndex = null">
            <dl v-if="selectedRow" class="detail-list">
                <div v-for="c in columns.slice(1)" :key="c.key">
                    <dt>{{ c.label }}</dt>
                    <dd :class="{ signal: (c.key === 'signal' || c.key === 'flag') && selectedRow[c.key] !== '—' }">{{ selectedRow[c.key] }}</dd>
                </div>
            </dl>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.decision { font-size: 0.85rem; margin: 0 0 0.75rem; }
.decision-label { font-weight: 600; color: var(--color-text-muted); }
.row-count { align-self: center; }

.notes { margin: 0 0 0.75rem 1.1rem; padding: 0; font-size: 0.78rem; color: var(--color-text-muted); }
.notes li { margin-bottom: 0.15rem; }

table { font-size: 0.84rem; }
th, td { padding: 0.5rem 0.75rem; }
.signal { color: var(--color-warning); font-weight: 600; }
.more-columns { margin: 0.6rem 0 0; }

.print-only { display: none; }

/* Las columnas de más solo se ocultan en pantalla. */
@media screen {
    .col-extra { display: none; }
}

@media print {
    /* Se imprime la tabla y nada más: filtros, botones y navegación no
       aportan en papel y se comen media hoja. */
    .no-print { display: none !important; }
    .print-only { display: block; }
    .print-header { margin-bottom: 0.5rem; font-size: 12pt; }

    .card { border: none; box-shadow: none; padding: 0; }
    table { font-size: 7pt; width: 100%; }
    th, td { padding: 2px 4px; border: 1px solid #ccc; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>
