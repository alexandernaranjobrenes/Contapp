<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

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
        <template #actions>
            <input v-model="asOf" type="date" class="date-input">
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.balance-sheet.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.balance-sheet.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="balance-sheet" :parameters="saveParameters" />
        </template>

        <p v-if="!result.is_balanced" class="warning">
            ⚠ El activo no cuadra contra pasivo + patrimonio — revisar.
        </p>

        <div class="columns">
            <div class="card">
                <h3>Activo</h3>
                <table>
                    <tbody>
                        <tr v-for="line in result.assets" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td :style="{ paddingLeft: (0.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
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

            <div class="card">
                <h3>Pasivo</h3>
                <table>
                    <tbody>
                        <tr v-for="line in result.liabilities" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td :style="{ paddingLeft: (0.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
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

                <h3 class="mt">Patrimonio</h3>
                <table>
                    <tbody>
                        <tr v-for="line in result.equity" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td :style="{ paddingLeft: (0.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amount) }}</td>
                        </tr>
                        <tr>
                            <td>Utilidad (pérdida) del ejercicio</td>
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
    </AppLayout>
</template>

<style scoped>
.columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; }
.card { padding: 1rem 1.25rem; }
h3 { margin: 0 0 0.5rem; font-size: 0.95rem; }
h3.mt { margin-top: 1.5rem; }
table { width: 100%; font-size: 0.85rem; }
td { padding: 0.4rem 0.2rem; border-top: 1px solid var(--color-border); }
.num { text-align: right; }
.is-header td { font-weight: 700; }
.total-label { text-align: right; font-weight: 700; border-top: 2px solid var(--color-border); }
.total-value { font-weight: 800; color: var(--color-primary); border-top: 2px solid var(--color-border); }
.grand-total { margin-top: 0.75rem; }
.warning { color: #cc0000; font-weight: 600; margin-bottom: 1rem; }

.date-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
}
</style>
