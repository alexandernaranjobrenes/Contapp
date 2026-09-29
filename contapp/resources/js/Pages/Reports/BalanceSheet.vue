<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';
import { DownloadIcon, TriangleAlertIcon } from '@lucide/vue';

const props = defineProps({
    asOf: { type: String, required: true },
    result: { type: Object, required: true },
});

const asOf = ref(props.asOf);

const saveParameters = computed(() => ({ as_of: wrapDate(asOf.value) }));

function applyFilter() {
    router.get(route('reports.balance-sheet.index'), { as_of: asOf.value }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, { as_of: asOf.value });
}
</script>

<template>
    <Head title="Balance general" />

    <AppLayout title="Balance general">
        <div class="view-toolbar">
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Al</span>
                    <input v-model="asOf" type="date" required>
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>

            <div class="view-actions">
                <a :href="exportUrl('reports.balance-sheet.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.balance-sheet.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <SaveReportButton report-code="balance-sheet" :parameters="saveParameters" />
            </div>
        </div>

        <p v-if="!result.is_balanced" class="flash flash-error warning">
            <TriangleAlertIcon /> El activo no cuadra contra pasivo + patrimonio — revisar.
        </p>

        <!-- Dos estados de dos columnas cada uno: caben como tabla hasta en un
             teléfono (.no-cards); lado a lado en un monitor, uno debajo del
             otro en pantallas angostas. -->
        <div class="columns">
            <div class="card">
                <h3>Activo</h3>
                <div class="table-responsive no-cards">
                    <table>
                        <tbody>
                            <tr v-for="line in result.assets" :key="line.code" :class="{ 'is-header': line.is_header }">
                                <td class="desc-cell" :style="{ '--depth': line.depth }">{{ line.description }}</td>
                                <td class="num">{{ formatMoney(line.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="total-label">Total activo</td>
                                <td class="num total-value">{{ formatMoney(result.assets_total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3>Pasivo</h3>
                <div class="table-responsive no-cards">
                    <table>
                        <tbody>
                            <tr v-for="line in result.liabilities" :key="line.code" :class="{ 'is-header': line.is_header }">
                                <td class="desc-cell" :style="{ '--depth': line.depth }">{{ line.description }}</td>
                                <td class="num">{{ formatMoney(line.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="total-label">Total pasivo</td>
                                <td class="num total-value">{{ formatMoney(result.liabilities_total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <h3 class="mt">Patrimonio</h3>
                <div class="table-responsive no-cards">
                    <table>
                        <tbody>
                            <tr v-for="line in result.equity" :key="line.code" :class="{ 'is-header': line.is_header }">
                                <td class="desc-cell" :style="{ '--depth': line.depth }">{{ line.description }}</td>
                                <td class="num">{{ formatMoney(line.amount) }}</td>
                            </tr>
                            <tr>
                                <td class="desc-cell">Utilidad (pérdida) del ejercicio</td>
                                <td class="num">{{ formatMoney(result.current_year_earnings) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="total-label">Total patrimonio</td>
                                <td class="num total-value">{{ formatMoney(result.total_equity_and_earnings) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="table-responsive no-cards">
                    <table class="grand-total">
                        <tfoot>
                            <tr>
                                <td class="total-label">Total pasivo + patrimonio</td>
                                <td class="num total-value">{{ formatMoney(result.total_liabilities_and_equity) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.columns { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr)); gap: 1.5rem; align-items: start; }
.card { padding: 1rem 1.25rem; }
h3 { margin: 0 0 0.5rem; font-size: 0.95rem; }
h3.mt { margin-top: 1.5rem; }
table { font-size: 0.85rem; }
td { padding: 0.4rem 0.2rem; border-top: 1px solid var(--color-border); }
.desc-cell {
    padding-left: calc(0.2rem + var(--depth, 0) * 1.1rem);
    overflow-wrap: anywhere;
}
.is-header td { font-weight: 700; }
.total-label { text-align: right; font-weight: 700; border-top: 2px solid var(--color-border); }
.total-value { font-weight: 800; color: var(--color-primary); border-top: 2px solid var(--color-border); }
.grand-total { margin-top: 0.75rem; }
.warning { display: flex; align-items: center; gap: 0.4rem; font-weight: 600; }

@media (max-width: 640px) {
    .card { padding: 0.9rem 1rem; }
    .desc-cell { padding-left: calc(0.2rem + var(--depth, 0) * 0.6rem); }
    .total-label { text-align: left; }
}
</style>
