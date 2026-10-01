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
            />
        </section>
    </AppLayout>
</template>

<style scoped>
.section { margin-bottom: 2rem; }
h2 { font-size: 1rem; margin: 0 0 0.75rem; }
.empty { text-align: center; color: var(--color-text-muted); padding: 1.5rem; }
</style>
