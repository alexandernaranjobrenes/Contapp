<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    from: { type: String, default: null },
    to: { type: String, default: null },
    result: { type: Object, required: true },
});

const from = ref(props.from);
const to = ref(props.to);

const saveParameters = computed(() => {
    const params = {};
    if (from.value) params.from = wrapDate(from.value);
    if (to.value) params.to = wrapDate(to.value);
    return params;
});

function applyFilter() {
    router.get(route('reports.cost-allocation-rule.index'), {
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, { from: from.value || undefined, to: to.value || undefined });
}

// Más de 1 punto porcentual de diferencia entre lo definido y lo realmente
// contabilizado es la señal visual de "revisá esto" — un desvío de
// redondeo normal (ver CostAllocationSplitter, la última línea absorbe el
// resto) queda muy por debajo de ese umbral.
function isOffNorm(line) {
    if (line.variance_percentage_points === null) return false;
    return Math.abs(parseFloat(line.variance_percentage_points)) > 1;
}
</script>

<template>
    <Head title="Normas de reparto" />

    <AppLayout title="Normas de reparto">
        <template #actions>
            <input v-model="from" type="date" class="date-input">
            <span class="to-label">a</span>
            <input v-model="to" type="date" class="date-input">
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.cost-allocation-rule.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.cost-allocation-rule.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="cost-allocation-rule" :parameters="saveParameters" />
        </template>

        <p class="hint">
            Para cada norma de reparto, compara el % que define hoy contra el % que realmente resultó de sumar los
            asientos contabilizados en el período. Una fila resaltada significa más de 1 punto porcentual de diferencia
            — más que el redondeo esperado (ver <em>CostAllocationSplitter</em>), vale la pena revisarla.
        </p>

        <div v-for="group in result.groups" :key="group.rule_id" class="card group-card">
            <div class="group-header">
                <strong>{{ group.rule_code }} — {{ group.rule_name }}</strong>
                <span class="muted">Total distribuido: {{ formatMoney(group.total_amount) }}</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Centro de costo</th>
                        <th class="num">% definido</th>
                        <th class="num">Monto real</th>
                        <th class="num">% real</th>
                        <th class="num">Variación (p.p.)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in group.lines" :key="line.cost_center_id" :class="{ 'off-norm': isOffNorm(line) }">
                        <td>{{ line.cost_center_code }} — {{ line.cost_center_name }}</td>
                        <td class="num">{{ line.defined_percentage }}%</td>
                        <td class="num">{{ formatMoney(line.actual_amount) }}</td>
                        <td class="num">{{ line.actual_percentage !== null ? line.actual_percentage + '%' : '—' }}</td>
                        <td class="num">{{ line.variance_percentage_points ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="result.groups.length === 0" class="card empty-card">
            Sin movimientos generados por normas de reparto para el período seleccionado.
        </div>
    </AppLayout>
</template>

<style scoped>
table { width: 100%; font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1.1rem; border-top: 1px solid var(--color-border); }
.num { text-align: right; }

.hint { color: var(--color-text-muted); font-size: 0.8rem; margin-bottom: 1rem; max-width: 720px; }

.group-card { margin-bottom: 1rem; padding: 0; overflow: hidden; }
.group-header {
    padding: 0.75rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-surface-alt);
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    font-size: 0.9rem;
}
.muted { color: var(--color-text-muted); font-size: 0.8rem; font-weight: 400; }

.off-norm td { background: var(--color-warning-soft); color: var(--color-warning); font-weight: 600; }

.empty-card { padding: 1.1rem; color: var(--color-text-muted); }

.date-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
}
.to-label { color: var(--color-text-muted); font-size: 0.82rem; }
</style>
