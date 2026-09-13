<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    from1: { type: String, required: true },
    to1: { type: String, required: true },
    from2: { type: String, required: true },
    to2: { type: String, required: true },
    result: { type: Object, required: true },
});

const from1 = ref(props.from1);
const to1 = ref(props.to1);
const from2 = ref(props.from2);
const to2 = ref(props.to2);

const saveParameters = computed(() => ({
    from_1: wrapDate(from1.value),
    to_1: wrapDate(to1.value),
    from_2: wrapDate(from2.value),
    to_2: wrapDate(to2.value),
}));

function currentParams() {
    return { from_1: from1.value, to_1: to1.value, from_2: from2.value, to_2: to2.value };
}

function applyFilter() {
    router.get(route('reports.period-comparison.index'), currentParams(), { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, currentParams());
}

function varianceClass(amounts) {
    if (amounts.variance === '0.00') return '';
    return parseFloat(amounts.variance) > 0 ? 'variance-positive' : 'variance-negative';
}

const balanceSheetSections = [
    { key: 'assets', label: 'Activo', totalKey: 'assets_total' },
    { key: 'liabilities', label: 'Pasivo', totalKey: 'liabilities_total' },
    { key: 'equity', label: 'Patrimonio', totalKey: 'equity_total' },
];

const incomeSections1 = [
    { key: 'sales', label: 'Ventas', totalKey: 'sales_total' },
    { key: 'cost_of_sales', label: 'Costo de ventas', totalKey: 'cost_of_sales_total' },
];
const incomeSections2 = [
    { key: 'operating_expenses', label: 'Gastos operativos', totalKey: 'operating_expenses_total' },
];
const incomeSections3 = [
    { key: 'other_income', label: 'Otros ingresos', totalKey: 'other_income_total' },
    { key: 'other_expense', label: 'Otros gastos', totalKey: 'other_expense_total' },
];
</script>

<template>
    <Head title="Comparativo entre periodos" />

    <AppLayout title="Comparativo entre periodos">
        <template #actions>
            <span class="label">Periodo 1</span>
            <input v-model="from1" type="date" class="date-input">
            <span class="to-label">a</span>
            <input v-model="to1" type="date" class="date-input">
            <span class="label">Periodo 2</span>
            <input v-model="from2" type="date" class="date-input">
            <span class="to-label">a</span>
            <input v-model="to2" type="date" class="date-input">
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.period-comparison.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.period-comparison.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="period-comparison" :parameters="saveParameters" />
        </template>

        <p class="hint">
            Balance general al cierre de cada periodo (saldo acumulado a la fecha "hasta") y estado de resultados con
            la actividad propia de cada periodo — mismo criterio de presentación comparativa NIIF.
        </p>

        <h2 class="report-section-title">Balance general</h2>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Cuenta</th>
                        <th class="num">Periodo 1</th>
                        <th class="num">Periodo 2</th>
                        <th class="num">Variación</th>
                        <th class="num">Variación %</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="section in balanceSheetSections" :key="section.key">
                        <tr class="section-label"><td colspan="5">{{ section.label }}</td></tr>
                        <tr v-for="line in result[section.key]" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td class="indent" :style="{ paddingLeft: (2.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_1) }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_2) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ formatMoney(line.amounts.variance) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ line.amounts.variance_percent !== null ? line.amounts.variance_percent + '%' : '—' }}</td>
                        </tr>
                        <tr class="section-total">
                            <td>Total {{ section.label.toLowerCase() }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_1) }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_2) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ formatMoney(result[section.totalKey].variance) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ result[section.totalKey].variance_percent !== null ? result[section.totalKey].variance_percent + '%' : '—' }}</td>
                        </tr>
                    </template>
                    <tr class="subtotal">
                        <td>Total pasivo + patrimonio</td>
                        <td class="num">{{ formatMoney(result.total_liabilities_and_equity.period_1) }}</td>
                        <td class="num">{{ formatMoney(result.total_liabilities_and_equity.period_2) }}</td>
                        <td class="num" :class="varianceClass(result.total_liabilities_and_equity)">{{ formatMoney(result.total_liabilities_and_equity.variance) }}</td>
                        <td class="num" :class="varianceClass(result.total_liabilities_and_equity)">{{ result.total_liabilities_and_equity.variance_percent !== null ? result.total_liabilities_and_equity.variance_percent + '%' : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="report-section-title">Estado de resultados</h2>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Cuenta</th>
                        <th class="num">Periodo 1</th>
                        <th class="num">Periodo 2</th>
                        <th class="num">Variación</th>
                        <th class="num">Variación %</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="section in incomeSections1" :key="section.key">
                        <tr class="section-label"><td colspan="5">{{ section.label }}</td></tr>
                        <tr v-for="line in result[section.key]" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td class="indent" :style="{ paddingLeft: (2.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_1) }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_2) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ formatMoney(line.amounts.variance) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ line.amounts.variance_percent !== null ? line.amounts.variance_percent + '%' : '—' }}</td>
                        </tr>
                        <tr class="section-total">
                            <td>Total {{ section.label.toLowerCase() }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_1) }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_2) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ formatMoney(result[section.totalKey].variance) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ result[section.totalKey].variance_percent !== null ? result[section.totalKey].variance_percent + '%' : '—' }}</td>
                        </tr>
                    </template>

                    <tr class="subtotal">
                        <td>Utilidad bruta</td>
                        <td class="num">{{ formatMoney(result.gross_profit.period_1) }}</td>
                        <td class="num">{{ formatMoney(result.gross_profit.period_2) }}</td>
                        <td class="num" :class="varianceClass(result.gross_profit)">{{ formatMoney(result.gross_profit.variance) }}</td>
                        <td class="num" :class="varianceClass(result.gross_profit)">{{ result.gross_profit.variance_percent !== null ? result.gross_profit.variance_percent + '%' : '—' }}</td>
                    </tr>

                    <template v-for="section in incomeSections2" :key="section.key">
                        <tr class="section-label"><td colspan="5">{{ section.label }}</td></tr>
                        <tr v-for="line in result[section.key]" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td class="indent" :style="{ paddingLeft: (2.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_1) }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_2) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ formatMoney(line.amounts.variance) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ line.amounts.variance_percent !== null ? line.amounts.variance_percent + '%' : '—' }}</td>
                        </tr>
                        <tr class="section-total">
                            <td>Total {{ section.label.toLowerCase() }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_1) }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_2) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ formatMoney(result[section.totalKey].variance) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ result[section.totalKey].variance_percent !== null ? result[section.totalKey].variance_percent + '%' : '—' }}</td>
                        </tr>
                    </template>

                    <tr class="subtotal">
                        <td>Utilidad operativa</td>
                        <td class="num">{{ formatMoney(result.operating_profit.period_1) }}</td>
                        <td class="num">{{ formatMoney(result.operating_profit.period_2) }}</td>
                        <td class="num" :class="varianceClass(result.operating_profit)">{{ formatMoney(result.operating_profit.variance) }}</td>
                        <td class="num" :class="varianceClass(result.operating_profit)">{{ result.operating_profit.variance_percent !== null ? result.operating_profit.variance_percent + '%' : '—' }}</td>
                    </tr>

                    <template v-for="section in incomeSections3" :key="section.key">
                        <tr class="section-label"><td colspan="5">{{ section.label }}</td></tr>
                        <tr v-for="line in result[section.key]" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td class="indent" :style="{ paddingLeft: (2.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_1) }}</td>
                            <td class="num">{{ formatMoney(line.amounts.period_2) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ formatMoney(line.amounts.variance) }}</td>
                            <td class="num" :class="varianceClass(line.amounts)">{{ line.amounts.variance_percent !== null ? line.amounts.variance_percent + '%' : '—' }}</td>
                        </tr>
                        <tr class="section-total">
                            <td>Total {{ section.label.toLowerCase() }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_1) }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey].period_2) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ formatMoney(result[section.totalKey].variance) }}</td>
                            <td class="num" :class="varianceClass(result[section.totalKey])">{{ result[section.totalKey].variance_percent !== null ? result[section.totalKey].variance_percent + '%' : '—' }}</td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="total-label">Utilidad neta</td>
                        <td class="num total-value">{{ formatMoney(result.net_profit.period_1) }}</td>
                        <td class="num total-value">{{ formatMoney(result.net_profit.period_2) }}</td>
                        <td class="num total-value" :class="varianceClass(result.net_profit)">{{ formatMoney(result.net_profit.variance) }}</td>
                        <td class="num total-value" :class="varianceClass(result.net_profit)">{{ result.net_profit.variance_percent !== null ? result.net_profit.variance_percent + '%' : '—' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { width: 100%; font-size: 0.85rem; }
td { padding: 0.45rem 1.1rem; }
th { text-align: left; padding: 0.5rem 1.1rem; }
.num { text-align: right; }
.indent { color: var(--color-text-muted); }
.is-header td { font-weight: 700; color: var(--color-text); }
.section-label td { font-weight: 700; padding-top: 1rem; color: var(--color-text-muted); }
.section-total td { border-top: 1px solid var(--color-border); font-weight: 600; }
.subtotal td { border-top: 1px solid var(--color-border); border-bottom: 1px solid var(--color-border); font-weight: 700; padding: 0.6rem 1.1rem; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; }

.variance-positive { color: var(--color-success); }
.variance-negative { color: var(--color-danger); }

.report-section-title { font-size: 0.95rem; margin: 1.25rem 0 0.5rem; color: var(--color-text-muted); }
.report-section-title:first-of-type { margin-top: 0; }

.hint { color: var(--color-text-muted); font-size: 0.8rem; margin-bottom: 1rem; max-width: 720px; }

.label { color: var(--color-text-muted); font-size: 0.82rem; }
.date-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
}
.to-label { color: var(--color-text-muted); font-size: 0.82rem; }
</style>
