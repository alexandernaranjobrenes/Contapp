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
        <template #actions>
            <input v-model="from" type="date" class="date-input">
            <span class="to-label">a</span>
            <input v-model="to" type="date" class="date-input">
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
        </template>

        <div class="card">
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
                        <td class="num">{{ summary[row.key]?.base ?? '0.00' }}</td>
                        <td class="num">{{ summary[row.key]?.tax ?? '0.00' }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="total-label">Neto a pagar (Devengado − Soportado)</td>
                        <td class="num total-value">{{ summary.neto_a_pagar }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="hint">
            Calculado a partir de los movimientos con impuesto asociado entre {{ from }} y {{ to }}.
            No recalcula indicadores históricos: una corrección posterior a un indicador no altera un período ya declarado.
        </p>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.6rem 1.1rem; border-top: 1px solid var(--color-border); }
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

.hint { color: var(--color-text-muted); font-size: 0.8rem; margin-top: 1rem; max-width: 640px; }
</style>
