<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { ArrowLeftIcon, Columns3Icon, DownloadIcon, PrinterIcon } from '@lucide/vue';

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
    router.get(route('accounting-reports.show', props.report.code), queryParams(), {
        preserveState: true, replace: true, preserveScroll: true,
    });
}

function clearFilters() {
    draft.value = {};
    router.get(route('accounting-reports.show', props.report.code), {}, { preserveState: false });
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

// Algunos reportes traen una docena de columnas, o las que el usuario
// escoja. En pantalla se ven las primeras VISIBLE —la identidad del
// trabajador y sus cifras principales—, así la tabla cabe sin desplazarse de
// lado (CLAUDE.md secc. 20); el resto está en la ficha de la fila. El Excel,
// el PDF y la impresión las traen todas.
const VISIBLE = 6;
const hasHidden = computed(() => props.columns.length > VISIBLE);

const selectedIndex = ref(null);
const selectedRow = computed(() => (selectedIndex.value === null ? null : props.rows[selectedIndex.value] ?? null));

function openRow(index) {
    if (hasHidden.value) selectedIndex.value = index;
}

function applyColumns() {
    pickingColumns.value = false;
    apply();
}
</script>

<template>
    <Head :title="report.label" />

    <AppLayout :title="report.label">
        <div class="view-toolbar">
            <Link :href="route('accounting-reports.index')" class="btn btn-ghost"><ArrowLeftIcon /> Todos los reportes</Link>
            <div class="view-actions">
                <a :href="outputUrl('accounting-reports.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="outputUrl('accounting-reports.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <!--
                    Imprimir abre el MISMO PDF en el navegador: un reporte de
                    planilla que se imprime se firma y se archiva, y tiene que
                    salir con el encabezado de la empresa y los filtros con que se
                    corrió. Imprimir la pantalla daría una hoja anónima.
                -->
                <a :href="outputUrl('accounting-reports.print')" target="_blank" rel="noopener" class="btn btn-ghost">
                    <PrinterIcon /> Imprimir
                </a>
            </div>
        </div>

        <p class="decision">
            <span class="decision-label">Sirve para decidir:</span> {{ report.decision }}
        </p>

        <!-- Filtros del reporte, arriba de la tabla (CLAUDE.md secc. 24). -->
        <form class="view-toolbar" @submit.prevent="apply">
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
                <button v-if="availableColumns" type="button" class="btn btn-ghost" @click="pickingColumns = true">
                    <Columns3Icon /> Columnas ({{ picked.length }} de {{ availableColumns.length }})
                </button>
                <span class="muted small row-count">{{ rowCount }} fila(s)</span>
            </div>
        </form>

        <ul v-if="notes.length" class="notes">
            <li v-for="(n, i) in notes" :key="i">{{ n }}</li>
        </ul>

        <div class="card">
            <div class="table-responsive table-scroll" :class="{ 'freeze-2': report.frozen_columns === 2 }">
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
                                    signal: c.key === 'missing' && row[c.key] !== '—',
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

        <p v-if="hasHidden && rows.length" class="hint more-columns">
            Tocá una fila para ver sus {{ columns.length }} columnas; el XLSX, el PDF y la impresión las traen todas.
        </p>

        <DetailModal :open="!!selectedRow" :title="selectedRow ? String(selectedRow[columns[0]?.key] ?? '') : ''" @close="selectedIndex = null">
            <dl v-if="selectedRow" class="detail-list">
                <div v-for="c in columns.slice(1)" :key="c.key">
                    <dt>{{ c.label }}</dt>
                    <dd :class="{ signal: c.key === 'missing' && selectedRow[c.key] !== '—' }">{{ selectedRow[c.key] }}</dd>
                </div>
            </dl>
        </DetailModal>

        <DetailModal v-if="availableColumns" :open="pickingColumns" wide title="Columnas del reporte" @close="pickingColumns = false">
            <p class="hint">
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
                    {{ c.label }}
                </label>
            </div>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="allColumns">Marcar todas</button>
                <button type="button" class="btn btn-primary" @click="applyColumns">Aplicar columnas</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.decision { font-size: 0.85rem; margin: 0 0 0.75rem; }
.decision-label { font-weight: 600; color: var(--color-text-muted); }
.row-count { align-self: center; }

.columns-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 200px), 1fr));
    gap: 0.25rem 0.75rem;
}

.notes { margin: 0 0 0.75rem 1.1rem; padding: 0; font-size: 0.78rem; color: var(--color-text-muted); }
.notes li { margin-bottom: 0.15rem; }

table { font-size: 0.84rem; }
.table-scroll.freeze-2 { --freeze-1-width: 9rem; }

.signal { color: var(--color-warning); font-weight: 600; }
.more-columns { margin: 0.6rem 0 0; }

/* Las columnas de más solo se ocultan en pantalla. */
@media screen {
    .col-extra { display: none; }
}
</style>
