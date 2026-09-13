<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    asOf: { type: String, required: true },
    buckets: { type: String, default: null },
    result: { type: Object, required: true },
});

const asOf = ref(props.asOf);
const bucketMode = ref(props.buckets ? 'custom' : 'standard');
const customBuckets = ref(props.buckets ?? '');

const saveParameters = computed(() => ({
    as_of: wrapDate(asOf.value),
    buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
}));

function applyFilter() {
    router.get(route('reports.cash-flow-projection.index'), {
        as_of: asOf.value,
        buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
    }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, {
        as_of: asOf.value,
        buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
    });
}

const expandedRows = ref(new Set());

function rowKey(sectionKey, currencyCode, partnerCode) {
    return `${sectionKey}::${currencyCode}::${partnerCode}`;
}

function toggleRow(sectionKey, currencyCode, partnerCode) {
    const key = rowKey(sectionKey, currencyCode, partnerCode);
    const next = new Set(expandedRows.value);
    next.has(key) ? next.delete(key) : next.add(key);
    expandedRows.value = next;
}

function isExpanded(sectionKey, currencyCode, partnerCode) {
    return expandedRows.value.has(rowKey(sectionKey, currencyCode, partnerCode));
}

const bucketKeys = computed(() => Object.keys(props.result.bucket_labels));

const sections = [
    { key: 'collections', label: 'Cobros esperados (clientes)' },
    { key: 'payments', label: 'Pagos esperados (proveedores)' },
];
</script>

<template>
    <Head title="Proyección de cobros y pagos" />

    <AppLayout title="Proyección de cobros y pagos">
        <template #actions>
            <input v-model="asOf" type="date" class="date-input">
            <select v-model="bucketMode" class="select-input">
                <option value="standard">Cortes: estándar (15/30/60/90)</option>
                <option value="custom">Cortes: personalizados</option>
            </select>
            <input
                v-if="bucketMode === 'custom'"
                v-model="customBuckets"
                type="text"
                class="date-input buckets-input"
                placeholder="Ej. 10,30,60"
                title="Días de corte separados por coma, de menor a mayor"
            >
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.cash-flow-projection.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.cash-flow-projection.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="cash-flow-projection" :parameters="saveParameters" />
        </template>

        <div v-for="section in sections" :key="section.key" class="section">
            <h2>{{ section.label }}</h2>

            <div v-if="result[section.key].length === 0" class="card empty">
                Sin partidas pendientes.
            </div>

            <div v-for="group in result[section.key]" :key="group.currency_code" class="card group">
                <h3>Moneda: {{ group.currency_code }}</h3>
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Socio</th>
                            <th>Nombre</th>
                            <th v-for="key in bucketKeys" :key="key" class="num">{{ result.bucket_labels[key] }}</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in group.rows" :key="row.partner_code">
                            <tr class="partner-row" @click="toggleRow(section.key, group.currency_code, row.partner_code)">
                                <td class="expand-cell">{{ isExpanded(section.key, group.currency_code, row.partner_code) ? '▾' : '▸' }}</td>
                                <td>{{ row.partner_code }}</td>
                                <td>{{ row.partner_name }}</td>
                                <td v-for="key in bucketKeys" :key="key" class="num">{{ formatMoney(row.buckets[key]) }}</td>
                                <td class="num">{{ formatMoney(row.total) }}</td>
                            </tr>
                            <tr v-if="isExpanded(section.key, group.currency_code, row.partner_code)" class="documents-row">
                                <td :colspan="bucketKeys.length + 4">
                                    <table class="documents-table">
                                        <thead>
                                            <tr>
                                                <th>Documento</th>
                                                <th>Vencimiento</th>
                                                <th class="num">Monto original</th>
                                                <th class="num">Saldo pendiente</th>
                                                <th>Bucket</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(doc, i) in row.documents" :key="i">
                                                <td>{{ doc.document_label }}</td>
                                                <td>{{ doc.due_date ?? '—' }}</td>
                                                <td class="num">{{ formatMoney(doc.original_amount) }}</td>
                                                <td class="num">{{ formatMoney(doc.balance) }}</td>
                                                <td class="muted">{{ result.bucket_labels[doc.bucket] }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="total-label">Totales</td>
                            <td v-for="key in bucketKeys" :key="key" class="num total-value">{{ formatMoney(group.bucket_totals[key]) }}</td>
                            <td class="num total-value">{{ formatMoney(group.total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.section { margin-bottom: 2rem; }
h2 { font-size: 1rem; margin: 0 0 0.75rem; }
.group { margin-bottom: 1.5rem; }
h3 { margin: 0 0 0.75rem; font-size: 0.9rem; }
table { width: 100%; font-size: 0.82rem; border-collapse: collapse; }
th, td { text-align: left; padding: 0.5rem 0.8rem; border-top: 1px solid var(--color-border); }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; color: var(--color-primary); }
.empty { text-align: center; color: var(--color-text-muted); padding: 1.5rem; }
.muted { color: var(--color-text-muted); }

.partner-row {
    cursor: pointer;
}

.partner-row:hover {
    background: var(--color-surface-alt);
}

.expand-cell {
    width: 1.5rem;
    color: var(--color-text-muted);
    font-size: 0.7rem;
}

.documents-row td {
    padding: 0;
    border-top: none;
    background: var(--color-surface-alt);
}

.documents-table {
    font-size: 0.78rem;
    margin: 0.4rem 1rem 0.6rem 2.5rem;
    width: calc(100% - 3.5rem);
}

.documents-table th {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    padding: 0.3rem 0.6rem;
    border-top: none;
    border-bottom: 1px solid var(--color-border);
}

.documents-table td {
    padding: 0.3rem 0.6rem;
}

.date-input, .select-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
}

.buckets-input {
    width: 150px;
}
</style>
