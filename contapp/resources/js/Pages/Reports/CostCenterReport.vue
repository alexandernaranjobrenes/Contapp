<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
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
    router.get(route('reports.cost-center.index'), {
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, { from: from.value || undefined, to: to.value || undefined });
}

// Mismo panel de mayor auxiliar ya usado en Balance de comprobación / Catálogo
// de cuentas, acá sobre la dimensión "cost-center" — muestra TODOS los
// movimientos del centro (no solo la cuenta de la fila clicada), porque
// LedgerService no filtra por dos dimensiones a la vez.
const ledger = ref({ open: false, ownerId: null, ownerLabel: '' });

function openLedger(group) {
    ledger.value = { open: true, ownerId: group.cost_center_id, ownerLabel: `${group.cost_center_code} — ${group.cost_center_name}` };
}

function closeLedger() {
    ledger.value.open = false;
}
</script>

<template>
    <Head title="Auxiliar por centro de costo" />

    <AppLayout title="Auxiliar por centro de costo">
        <template #actions>
            <input v-model="from" type="date" class="date-input">
            <span class="to-label">a</span>
            <input v-model="to" type="date" class="date-input">
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.cost-center.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.cost-center.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="cost-center" :parameters="saveParameters" />
        </template>

        <p class="hint">
            Solo incluye líneas de asiento con un centro de costo asignado (directo o por norma de reparto).
            Para el detalle cronológico de movimientos de un solo centro, hacé clic en su nombre.
        </p>

        <div v-for="group in result.groups" :key="group.cost_center_id" class="card group-card">
            <div class="group-header">
                <button type="button" class="group-title-btn" title="Ver movimientos de este centro" @click="openLedger(group)">
                    {{ group.cost_center_code }} — {{ group.cost_center_name }}
                </button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Cuenta</th>
                        <th>Descripción</th>
                        <th class="num">Débito</th>
                        <th class="num">Crédito</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in group.lines" :key="line.account_id">
                        <td>{{ line.account_code }}</td>
                        <td>{{ line.account_description }}</td>
                        <td class="num">{{ formatMoney(line.debit) }}</td>
                        <td class="num">{{ formatMoney(line.credit) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="total-label">Subtotal</td>
                        <td class="num total-value">{{ formatMoney(group.total_debit) }}</td>
                        <td class="num total-value">{{ formatMoney(group.total_credit) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="result.groups.length === 0" class="card empty-card">
            Sin movimientos con centro de costo asignado para el período seleccionado.
        </div>

        <div v-else class="card grand-total-card">
            <span>Totales</span>
            <span class="num">Débito {{ formatMoney(result.grand_total_debit) }}</span>
            <span class="num">Crédito {{ formatMoney(result.grand_total_credit) }}</span>
        </div>

        <LedgerPanel
            :open="ledger.open"
            dimension="cost-center"
            :owner-id="ledger.ownerId"
            :owner-label="ledger.ownerLabel"
            @close="closeLedger"
        />
    </AppLayout>
</template>

<style scoped>
table { width: 100%; font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1.1rem; border-top: 1px solid var(--color-border); }
.num { text-align: right; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; color: var(--color-primary); }

.hint { color: var(--color-text-muted); font-size: 0.8rem; margin-bottom: 1rem; max-width: 680px; }

.group-card { margin-bottom: 1rem; padding: 0; overflow: hidden; }
.group-header { padding: 0.75rem 1.1rem; border-bottom: 1px solid var(--color-border); background: var(--color-surface-alt); }
.group-title-btn {
    background: none;
    border: none;
    padding: 0;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--color-primary);
    cursor: pointer;
    text-decoration: underline dotted;
}

.empty-card, .grand-total-card {
    padding: 1.1rem;
    color: var(--color-text-muted);
}

.grand-total-card {
    display: flex;
    justify-content: flex-end;
    gap: 1.5rem;
    font-weight: 700;
    color: var(--color-text);
}

.date-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
}
.to-label { color: var(--color-text-muted); font-size: 0.82rem; }
</style>
