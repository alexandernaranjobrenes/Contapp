<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    summary: { type: Object, required: true },
});

const from = ref(props.from);
const to = ref(props.to);

function applyFilter() {
    router.get(route('tax-report.index'), { from: from.value, to: to.value }, { preserveState: true });
}

const rows = [
    { key: 'iva_devengado', label: 'IVA Devengado (débito fiscal — ventas)' },
    { key: 'iva_soportado', label: 'IVA Soportado (crédito fiscal — compras)' },
    { key: 'iva_general', label: 'IVA General' },
];
</script>

<template>
    <Head title="Reporte de IVA" />

    <AppLayout title="Reporte de IVA">
        <div class="view-toolbar">
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Desde</span>
                    <input v-model="from" type="date">
                </label>
                <label class="filter-field">
                    <span>Hasta</span>
                    <input v-model="to" type="date">
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Clasificación</th>
                            <th class="num">Base gravable</th>
                            <th class="num">Impuesto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.key">
                            <td>{{ row.label }}</td>
                            <td data-label="Base gravable" class="num">{{ summary[row.key]?.base ?? '0.00' }}</td>
                            <td data-label="Impuesto" class="num">{{ summary[row.key]?.tax ?? '0.00' }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="total-label">Neto a pagar (Devengado − Soportado)</td>
                            <td data-label="Neto a pagar" class="num total-value">{{ summary.neto_a_pagar }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="hint">
            Calculado a partir de los movimientos con impuesto asociado entre {{ from }} y {{ to }}.
            No recalcula indicadores históricos: una corrección posterior a un indicador no altera un período ya declarado.
        </p>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; color: var(--color-primary); }
.hint { margin-top: 1rem; }

@media screen and (max-width: 1024px) {
    .total-label { text-align: left; }
}
</style>
