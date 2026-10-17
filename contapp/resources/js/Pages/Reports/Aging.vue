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
    partnerType: { type: String, required: true },
    buckets: { type: String, default: null },
    businessPartnerId: { type: [Number, String], default: null },
    partnerCodeFrom: { type: String, default: null },
    partnerCodeTo: { type: String, default: null },
    includeDocuments: { type: Boolean, default: false },
    businessPartners: { type: Array, default: () => [] },
    result: { type: Object, required: true },
});

const asOf = ref(props.asOf);
const partnerType = ref(props.partnerType);

// "Estándar" reproduce el corte de siempre (30/60/90) sin mandar el
// parámetro; "Personalizado" habilita el campo de texto — precargado con lo
// que ya venía aplicado, si la pantalla llegó con ?buckets= en la URL o
// desde un reporte guardado.
const bucketMode = ref(props.buckets ? 'custom' : 'standard');
const customBuckets = ref(props.buckets ?? '');

// Socio: "Todos" (por defecto), "Uno específico" (un combobox código+nombre,
// igual que en Registros) o "Rango de códigos" (desde/hasta, alfabético,
// mismo criterio que cualquier otro filtro "de cuenta X a cuenta Y" del
// sistema) — alternativos en pantalla, precargados según lo que haya traído
// la URL o un reporte guardado.
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

// Con documentos o sin ellos: lo decide la persona, no un formato fijo por
// archivo — el mismo interruptor vale para el XLSX, el PDF y lo que se
// imprime (ver AgingGroupTable.vue, que arma la tabla extra solo para
// imprimir cuando está prendido).
const includeDocuments = ref(props.includeDocuments);

const commonParams = computed(() => ({
    as_of: asOf.value,
    partner_type: partnerType.value,
    buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
    include_documents: includeDocuments.value ? 1 : 0,
    ...partnerParams.value,
}));

const saveParameters = computed(() => ({ ...commonParams.value, as_of: wrapDate(asOf.value) }));

function applyFilter() {
    router.get(route('reports.aging.index'), commonParams.value, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, commonParams.value);
}

function print() {
    window.print();
}
</script>

<template>
    <Head title="Antigüedad de saldos" />

    <AppLayout title="Antigüedad de saldos">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reports.aging.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.aging.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <button type="button" class="btn btn-ghost no-print" @click="print"><PrinterIcon /> Imprimir</button>
                <SaveReportButton report-code="aging" :parameters="saveParameters" />
            </div>
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Al</span>
                    <input v-model="asOf" type="date">
                </label>
                <label class="filter-field">
                    <span>Socios</span>
                    <select v-model="partnerType">
                        <option value="both">Clientes y proveedores</option>
                        <option value="client">Solo clientes</option>
                        <option value="supplier">Solo proveedores</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Cortes</span>
                    <select v-model="bucketMode">
                        <option value="standard">Estándar (30/60/90)</option>
                        <option value="custom">Personalizados</option>
                    </select>
                </label>
                <label v-if="bucketMode === 'custom'" class="filter-field" title="Días de corte separados por coma, de menor a mayor">
                    <span>Días de corte</span>
                    <input v-model="customBuckets" type="text" placeholder="Ej. 15,45,90,180">
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

        <div v-if="result.groups.length === 0" class="card empty">
            Sin partidas pendientes para los filtros seleccionados.
        </div>

        <AgingGroupTable
            v-for="group in result.groups"
            :key="group.currency_code"
            :group="group"
            :bucket-labels="result.bucket_labels"
            :print-documents="includeDocuments"
        />
    </AppLayout>
</template>

<style scoped>
.empty { text-align: center; color: var(--color-text-muted); padding: 1.5rem; }

.partner-combobox-field {
    width: 16rem;
    max-width: 100%;
}
</style>
