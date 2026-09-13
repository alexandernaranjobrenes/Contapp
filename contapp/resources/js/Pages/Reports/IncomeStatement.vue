<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    hideZero: { type: Boolean, required: true },
    result: { type: Object, required: true },
});

const from = ref(props.from);
const to = ref(props.to);
const hideZero = ref(props.hideZero);

const saveParameters = computed(() => ({
    from: wrapDate(from.value),
    to: wrapDate(to.value),
    hide_zero: hideZero.value,
}));

function applyFilter() {
    router.get(route('reports.income-statement.index'), {
        from: from.value,
        to: to.value,
        hide_zero: hideZero.value ? 1 : 0,
    }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, { from: from.value, to: to.value, hide_zero: hideZero.value ? 1 : 0 });
}

const sections = [
    { key: 'sales', label: 'Ventas', totalKey: 'sales_total' },
    { key: 'cost_of_sales', label: 'Costo de ventas', totalKey: 'cost_of_sales_total' },
];
const sections2 = [
    { key: 'operating_expenses', label: 'Gastos operativos', totalKey: 'operating_expenses_total' },
];
const sections3 = [
    { key: 'other_income', label: 'Otros ingresos', totalKey: 'other_income_total' },
    { key: 'other_expense', label: 'Otros gastos', totalKey: 'other_expense_total' },
];
</script>

<template>
    <Head title="Estado de resultados" />

    <AppLayout title="Estado de resultados">
        <template #actions>
            <input v-model="from" type="date" class="date-input">
            <span class="to-label">a</span>
            <input v-model="to" type="date" class="date-input">
            <label class="hide-zero">
                <input v-model="hideZero" type="checkbox">
                Ocultar cuentas sin movimiento
            </label>
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.income-statement.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.income-statement.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="income-statement" :parameters="saveParameters" />
        </template>

        <div class="card">
            <table>
                <tbody>
                    <template v-for="section in sections" :key="section.key">
                        <tr class="section-label"><td colspan="2">{{ section.label }}</td></tr>
                        <tr v-for="line in result[section.key]" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td class="indent" :style="{ paddingLeft: (2.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amount) }}</td>
                        </tr>
                        <tr class="section-total">
                            <td>Total {{ section.label.toLowerCase() }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey]) }}</td>
                        </tr>
                    </template>

                    <tr class="subtotal"><td>Utilidad bruta</td><td class="num">{{ formatMoney(result.gross_profit) }}</td></tr>

                    <template v-for="section in sections2" :key="section.key">
                        <tr class="section-label"><td colspan="2">{{ section.label }}</td></tr>
                        <tr v-for="line in result[section.key]" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td class="indent" :style="{ paddingLeft: (2.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amount) }}</td>
                        </tr>
                        <tr class="section-total">
                            <td>Total {{ section.label.toLowerCase() }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey]) }}</td>
                        </tr>
                    </template>

                    <tr class="subtotal"><td>Utilidad operativa</td><td class="num">{{ formatMoney(result.operating_profit) }}</td></tr>

                    <template v-for="section in sections3" :key="section.key">
                        <tr class="section-label"><td colspan="2">{{ section.label }}</td></tr>
                        <tr v-for="line in result[section.key]" :key="line.code" :class="{ 'is-header': line.is_header }">
                            <td class="indent" :style="{ paddingLeft: (2.2 + line.depth * 1.1) + 'rem' }">{{ line.description }}</td>
                            <td class="num">{{ formatMoney(line.amount) }}</td>
                        </tr>
                        <tr class="section-total">
                            <td>Total {{ section.label.toLowerCase() }}</td>
                            <td class="num">{{ formatMoney(result[section.totalKey]) }}</td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="total-label">Utilidad neta del período</td>
                        <td class="num total-value">{{ formatMoney(result.net_profit) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { width: 100%; font-size: 0.85rem; }
td { padding: 0.45rem 1.1rem; }
.num { text-align: right; }
.indent { color: var(--color-text-muted); }
.is-header td { font-weight: 700; color: var(--color-text); }
.section-label td { font-weight: 700; padding-top: 1rem; }
.section-total td { border-top: 1px solid var(--color-border); font-weight: 600; }
.subtotal td { border-top: 1px solid var(--color-border); border-bottom: 1px solid var(--color-border); font-weight: 700; padding: 0.6rem 1.1rem; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; color: var(--color-primary); }

.date-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
}
.to-label { color: var(--color-text-muted); font-size: 0.82rem; }
.hide-zero { display: flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; color: var(--color-text-muted); }
</style>
