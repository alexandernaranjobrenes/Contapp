<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { DownloadIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    asOf: { type: String, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    result: { type: Object, required: true },
});

const asOf = ref(props.asOf);
const from = ref(props.from);
const to = ref(props.to);

const saveParameters = computed(() => ({
    as_of: wrapDate(asOf.value),
    from: wrapDate(from.value),
    to: wrapDate(to.value),
}));

function applyFilter() {
    router.get(route('reports.multi-company-comparison.index'), {
        as_of: asOf.value, from: from.value, to: to.value,
    }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, { as_of: asOf.value, from: from.value, to: to.value });
}
</script>

<template>
    <Head title="Comparativo de empresas" />

    <AppLayout title="Comparativo de empresas">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reports.multi-company-comparison.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.multi-company-comparison.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <SaveReportButton report-code="multi-company-comparison" :parameters="saveParameters" />
            </div>
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Balance al</span>
                    <input v-model="asOf" type="date" required>
                </label>
                <label class="filter-field">
                    <span>Actividad desde</span>
                    <input v-model="from" type="date" required>
                </label>
                <label class="filter-field">
                    <span>Actividad hasta</span>
                    <input v-model="to" type="date" required>
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>
        </div>

        <p class="hint">
            Cada empresa mantiene su propio catálogo de cuentas y moneda — las cifras se muestran una junto a otra,
            nunca sumadas entre empresas (podrían estar en monedas distintas).
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th class="num">Activo</th>
                            <th class="num">Pasivo</th>
                            <th class="num">Patrimonio</th>
                            <th class="num">Ventas del período</th>
                            <th class="num">Utilidad neta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in result.rows" :key="row.company_id">
                            <td>
                                {{ row.company_name }}
                                <span class="currency">{{ row.currency_code }}</span>
                            </td>
                            <td data-label="Activo" class="num">{{ formatMoney(row.assets_total) }}</td>
                            <td data-label="Pasivo" class="num">{{ formatMoney(row.liabilities_total) }}</td>
                            <td data-label="Patrimonio" class="num">{{ formatMoney(row.equity_total) }}</td>
                            <td data-label="Ventas del período" class="num">{{ formatMoney(row.sales_total) }}</td>
                            <td data-label="Utilidad neta" class="num">{{ formatMoney(row.net_profit) }}</td>
                        </tr>
                        <tr v-if="result.rows.length === 0">
                            <td colspan="6" class="muted empty-row">No hay otras empresas en este grupo (misma licencia) a las que tengas acceso.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Seis columnas que tienen que caber en los ~720px que deja la barra
   lateral a 1025px (CLAUDE.md secc. 20). */
table { font-size: 0.82rem; }
th, td { padding: 0.55rem 0.7rem; }
th.num { white-space: normal; }
.currency {
    display: inline-block;
    margin-left: 0.35rem;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--color-text-muted);
}
</style>
