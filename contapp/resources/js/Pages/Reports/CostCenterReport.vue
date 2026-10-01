<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';
import { DownloadIcon, ScrollTextIcon } from '@lucide/vue';

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
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reports.cost-center.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.cost-center.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <SaveReportButton report-code="cost-center" :parameters="saveParameters" />
            </div>
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

        <p class="hint">
            Solo incluye líneas de asiento con un centro de costo asignado (directo o por norma de reparto).
            Para el detalle cronológico de movimientos de un solo centro, usá «Ver movimientos».
        </p>

        <section v-for="group in result.groups" :key="group.cost_center_id" class="report-group">
            <div class="group-header">
                <h2 class="block-title">{{ group.cost_center_code }} — {{ group.cost_center_name }}</h2>
                <button type="button" class="btn btn-ghost" @click="openLedger(group)"><ScrollTextIcon /> Ver movimientos</button>
            </div>
            <div class="card">
                <div class="table-responsive">
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
                                <td class="code-cell">{{ line.account_code }}</td>
                                <td data-label="Descripción">{{ line.account_description }}</td>
                                <td data-label="Débito" class="num">{{ formatMoney(line.debit) }}</td>
                                <td data-label="Crédito" class="num">{{ formatMoney(line.credit) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="total-label">Subtotal</td>
                                <td data-label="Débito" class="num total-value">{{ formatMoney(group.total_debit) }}</td>
                                <td data-label="Crédito" class="num total-value">{{ formatMoney(group.total_credit) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </section>

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
table { font-size: 0.85rem; }
th, td { padding: 0.5rem 0.9rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; color: var(--color-primary); }

.report-group + .report-group { margin-top: 1.25rem; }

/* El centro de costo y su «Ver movimientos», arriba de su tabla. */
.group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem 1rem;
    margin-bottom: 0.6rem;
}

.group-header .block-title { margin: 0; }

.empty-card, .grand-total-card {
    margin-top: 1.25rem;
    padding: 1.1rem;
    color: var(--color-text-muted);
}

.grand-total-card {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.5rem 1.5rem;
    font-weight: 700;
    color: var(--color-text);
}

@media screen and (max-width: 1024px) {
    .total-label { text-align: left; }
    .grand-total-card { justify-content: flex-start; }
}
</style>
