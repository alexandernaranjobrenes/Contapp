<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
        <template #actions>
            <span class="label">Balance al</span>
            <input v-model="asOf" type="date" class="date-input">
            <span class="label">Actividad del</span>
            <input v-model="from" type="date" class="date-input">
            <span class="to-label">a</span>
            <input v-model="to" type="date" class="date-input">
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.multi-company-comparison.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.multi-company-comparison.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="multi-company-comparison" :parameters="saveParameters" />
        </template>

        <p class="hint">
            Cada empresa mantiene su propio catálogo de cuentas y moneda — las cifras se muestran una junto a otra,
            nunca sumadas entre empresas (podrían estar en monedas distintas).
        </p>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>Moneda</th>
                        <th class="num">Activo</th>
                        <th class="num">Pasivo</th>
                        <th class="num">Patrimonio</th>
                        <th class="num">Ventas del período</th>
                        <th class="num">Utilidad neta del período</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in result.rows" :key="row.company_id">
                        <td>{{ row.company_name }}</td>
                        <td>{{ row.currency_code }}</td>
                        <td class="num">{{ formatMoney(row.assets_total) }}</td>
                        <td class="num">{{ formatMoney(row.liabilities_total) }}</td>
                        <td class="num">{{ formatMoney(row.equity_total) }}</td>
                        <td class="num">{{ formatMoney(row.sales_total) }}</td>
                        <td class="num">{{ formatMoney(row.net_profit) }}</td>
                    </tr>
                    <tr v-if="result.rows.length === 0">
                        <td colspan="7" class="empty">No hay otras empresas en este grupo (misma licencia) a las que tengas acceso.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { width: 100%; font-size: 0.85rem; }
th, td { text-align: left; padding: 0.6rem 1.1rem; border-top: 1px solid var(--color-border); }
.num { text-align: right; }
.empty { text-align: center; color: var(--color-text-muted); padding: 1.5rem; }
.hint { color: var(--color-text-muted); font-size: 0.8rem; margin-bottom: 1rem; max-width: 640px; }

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
