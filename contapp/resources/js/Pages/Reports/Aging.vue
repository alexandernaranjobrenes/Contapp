<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';
import { DownloadIcon } from '@lucide/vue';
import AgingGroupTable from '../../Components/AgingGroupTable.vue';

const props = defineProps({
    asOf: { type: String, required: true },
    partnerType: { type: String, required: true },
    buckets: { type: String, default: null },
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

const saveParameters = computed(() => ({
    as_of: wrapDate(asOf.value),
    partner_type: partnerType.value,
    buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
}));

function applyFilter() {
    router.get(route('reports.aging.index'), {
        as_of: asOf.value,
        partner_type: partnerType.value,
        buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
    }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, {
        as_of: asOf.value,
        partner_type: partnerType.value,
        buckets: bucketMode.value === 'custom' ? customBuckets.value : null,
    });
}

</script>

<template>
    <Head title="Antigüedad de saldos" />

    <AppLayout title="Antigüedad de saldos">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reports.aging.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.aging.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
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
        />
    </AppLayout>
</template>

<style scoped>
.empty { text-align: center; color: var(--color-text-muted); padding: 1.5rem; }
</style>
