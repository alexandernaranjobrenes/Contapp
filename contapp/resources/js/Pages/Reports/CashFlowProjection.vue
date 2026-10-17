<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';
import { DownloadIcon, PrinterIcon } from '@lucide/vue';
import AgingGroupTable from '../../Components/AgingGroupTable.vue';
import SearchableCombobox from '../../Components/SearchableCombobox.vue';

const props = defineProps({
    asOf: { type: String, required: true },
    buckets: { type: String, default: null },
    businessPartnerId: { type: [Number, String], default: null },
    partnerCodeFrom: { type: String, default: null },
    partnerCodeTo: { type: String, default: null },
    includeDocuments: { type: Boolean, default: false },
    businessPartners: { type: Array, default: () => [] },
    result: { type: Object, required: true },
});

const asOf = ref(props.asOf);
const bucketMode = ref(props.buckets ? 'custom' : 'standard');
const customBuckets = ref(props.buckets ?? '');

// Socio: "Todos" (por defecto), "Uno específico" (combobox código+nombre) o
// "Rango de códigos" (desde/hasta) — mismo criterio que Antigüedad de saldos.
const partnerFilterMode = ref(
    props.businessPartnerId ? 'single' : (props.partnerCodeFrom || props.partnerCodeTo) ? 'range' : 'all'
);
const singlePartnerId = ref(props.businessPartnerId ?? null);
const partnerCodeFrom = ref(props.partnerCodeFrom ?? '');
const partnerCodeTo = ref(props.partnerCodeTo ?? '');

const partnerOptions = computed(() => props.businessPartners.map((p) => ({ id: p.id, code: p.code, label: p.name })));

const partnerParams = computed(() => {
    if (partnerFilterMode.value === 'single') {
        return { business_partner_id: singlePartnerId.value || null, partner_code_from: null, partner_code_to: null };
    }
    if (partnerFilterMode.value === 'range') {
        return { business_partner_id: null, partner_code_from: partnerCodeFrom.value || null, partner_code_to: partnerCodeTo.value || null };
    }
    return { business_partner_id: null, partner_code_from: null, partner_code_to: null };
});

// Con documentos o sin ellos: vale para el XLSX, el PDF y lo que se imprime
// (ver AgingGroupTable.vue) — mismo criterio que Antigüedad de saldos.
const includeDocuments = ref(props.includeDocuments);

const commonParams = computed(() => ({
    as_of: asOf.value,
    buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
    include_documents: includeDocuments.value ? 1 : 0,
    ...partnerParams.value,
}));

const saveParameters = computed(() => ({ ...commonParams.value, as_of: wrapDate(asOf.value) }));

function applyFilter() {
    router.get(route('reports.cash-flow-projection.index'), commonParams.value, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, commonParams.value);
}

function print() {
    window.print();
}

const sections = [
    { key: 'collections', label: 'Cobros esperados (clientes)' },
    { key: 'payments', label: 'Pagos esperados (proveedores)' },
];
</script>

<template>
    <Head title="Proyección de cobros y pagos" />

    <AppLayout title="Proyección de cobros y pagos">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reports.cash-flow-projection.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.cash-flow-projection.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <button type="button" class="btn btn-ghost no-print" @click="print"><PrinterIcon /> Imprimir</button>
                <SaveReportButton report-code="cash-flow-projection" :parameters="saveParameters" />
            </div>
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Al</span>
                    <input v-model="asOf" type="date">
                </label>
                <label class="filter-field">
                    <span>Cortes</span>
                    <select v-model="bucketMode">
                        <option value="standard">Estándar (15/30/60/90)</option>
                        <option value="custom">Personalizados</option>
                    </select>
                </label>
                <label v-if="bucketMode === 'custom'" class="filter-field" title="Días de corte separados por coma, de menor a mayor">
                    <span>Días de corte</span>
                    <input v-model="customBuckets" type="text" placeholder="Ej. 10,30,60">
                </label>
                <label class="filter-field">
                    <span>Filtrar por socio</span>
                    <select v-model="partnerFilterMode">
                        <option value="all">Todos</option>
                        <option value="single">Un socio específico</option>
                        <option value="range">Rango de códigos</option>
                    </select>
                </label>
                <label v-if="partnerFilterMode === 'single'" class="filter-field partner-combobox-field">
                    <span>Socio</span>
                    <SearchableCombobox v-model="singlePartnerId" :options="partnerOptions" placeholder="Código o nombre..." />
                </label>
                <template v-if="partnerFilterMode === 'range'">
                    <label class="filter-field">
                        <span>Código desde</span>
                        <input v-model="partnerCodeFrom" type="text" placeholder="Ej. C-001">
                    </label>
                    <label class="filter-field">
                        <span>Código hasta</span>
                        <input v-model="partnerCodeTo" type="text" placeholder="Ej. C-050">
                    </label>
                </template>
                <label class="check" title="En el XLSX, el PDF y al imprimir: el detalle de documentos de cada socio (número, vencimiento, monto original, saldo, tramo)">
                    <input v-model="includeDocuments" type="checkbox">
                    Incluir detalle de documentos
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>
        </div>

        <section v-for="section in sections" :key="section.key" class="section">
            <h2>{{ section.label }}</h2>

            <div v-if="result[section.key].length === 0" class="card empty">
                Sin partidas pendientes.
            </div>

            <AgingGroupTable
                v-for="group in result[section.key]"
                :key="group.currency_code"
                :group="group"
                :bucket-labels="result.bucket_labels"
                :print-documents="includeDocuments"
            />
        </section>
    </AppLayout>
</template>

<style scoped>
.section { margin-bottom: 2rem; }
h2 { font-size: 1rem; margin: 0 0 0.75rem; }
.empty { text-align: center; color: var(--color-text-muted); padding: 1.5rem; }

.partner-combobox-field {
    width: 16rem;
    max-width: 100%;
}
</style>
