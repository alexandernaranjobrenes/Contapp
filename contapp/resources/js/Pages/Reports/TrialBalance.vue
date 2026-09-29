<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { DownloadIcon, ScrollTextIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    from: { type: String, default: null },
    to: { type: String, default: null },
    hideZero: { type: Boolean, required: true },
    result: { type: Object, required: true },
});

const from = ref(props.from);
const to = ref(props.to);
const hideZero = ref(props.hideZero);

const saveParameters = computed(() => {
    const params = { hide_zero: hideZero.value };
    if (from.value) params.from = wrapDate(from.value);
    if (to.value) params.to = wrapDate(to.value);
    return params;
});

function applyFilter() {
    router.get(route('reports.trial-balance.index'), {
        from: from.value || undefined,
        to: to.value || undefined,
        hide_zero: hideZero.value ? 1 : 0,
    }, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, {
        from: from.value || undefined,
        to: to.value || undefined,
        hide_zero: hideZero.value ? 1 : 0,
    });
}

// Ficha de la cuenta (CLAUDE.md secc. 20): todas las cifras de la fila —la
// tabla muestra las que se comparan de un vistazo— y, en una cuenta hoja,
// «Ver movimientos», que abre el mismo mayor auxiliar del catálogo de
// cuentas. Las cuentas mayores (is_header) nunca reciben asientos directos:
// su mayor siempre estaría vacío.
const selectedCode = ref(null);
const selectedRow = computed(() => props.result.rows.find((r) => r.code === selectedCode.value) ?? null);

function openRow(row) {
    selectedCode.value = row.code;
}

function closeRow() {
    selectedCode.value = null;
}

const ledger = ref({ open: false, ownerId: null, ownerLabel: '' });

function showMovements() {
    const row = selectedRow.value;
    closeRow();
    ledger.value = { open: true, ownerId: row.account_id, ownerLabel: `${row.code} — ${row.description}` };
}

function closeLedger() {
    ledger.value.open = false;
}
</script>

<template>
    <Head title="Balance de comprobación" />

    <AppLayout title="Balance de comprobación">
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
                <label class="check">
                    <input v-model="hideZero" type="checkbox">
                    Ocultar cuentas sin movimiento
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>

            <div class="view-actions">
                <a :href="exportUrl('reports.trial-balance.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.trial-balance.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <SaveReportButton report-code="trial-balance" :parameters="saveParameters" />
            </div>
        </div>

        <div class="card">
            <div class="table-responsive table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th class="num">Saldo inicial</th>
                            <th class="num">Débito</th>
                            <th class="num">Crédito</th>
                            <th class="num">Saldo final</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in result.rows"
                            :key="row.code"
                            class="clickable-row"
                            :class="{ 'is-header': row.is_header }"
                            tabindex="0"
                            @click="openRow(row)"
                            @keydown.enter="openRow(row)"
                            @keydown.space.prevent="openRow(row)"
                        >
                            <td class="code-cell">{{ row.code }}</td>
                            <td data-label="Descripción" class="desc-cell" :style="{ '--depth': row.depth }">{{ row.description }}</td>
                            <td data-label="Saldo inicial" class="num">{{ formatMoney(row.opening_balance) }}</td>
                            <td data-label="Débito" class="num">{{ formatMoney(row.period_debit) }}</td>
                            <td data-label="Crédito" class="num">{{ formatMoney(row.period_credit) }}</td>
                            <td data-label="Saldo final" class="num">{{ formatMoney(row.closing_balance) }}</td>
                        </tr>
                        <tr v-if="result.rows.length === 0">
                            <td colspan="6" class="muted empty-row">Sin movimientos para los filtros seleccionados.</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="total-label">Totales</td>
                            <td data-label="Total débito" class="num total-value">{{ formatMoney(result.total_debit) }}</td>
                            <td data-label="Total crédito" class="num total-value">{{ formatMoney(result.total_credit) }}</td>
                            <td class="num"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selectedRow" :title="selectedRow ? `${selectedRow.code} — ${selectedRow.description}` : ''" @close="closeRow">
            <template #badge>
                <span v-if="selectedRow?.is_header" class="badge badge-neutral">Cuenta mayor</span>
            </template>

            <dl v-if="selectedRow" class="detail-list">
                <div>
                    <dt>Tipo</dt>
                    <dd>{{ selectedRow.account_type }}</dd>
                </div>
                <div>
                    <dt>Saldo inicial</dt>
                    <dd class="num-value">{{ formatMoney(selectedRow.opening_balance) }}</dd>
                </div>
                <div>
                    <dt>Débito del periodo</dt>
                    <dd class="num-value">{{ formatMoney(selectedRow.period_debit) }}</dd>
                </div>
                <div>
                    <dt>Crédito del periodo</dt>
                    <dd class="num-value">{{ formatMoney(selectedRow.period_credit) }}</dd>
                </div>
                <div>
                    <dt>Neto del periodo</dt>
                    <dd class="num-value">{{ formatMoney(selectedRow.period_net) }}</dd>
                </div>
                <div>
                    <dt>Saldo final</dt>
                    <dd class="num-value">{{ formatMoney(selectedRow.closing_balance) }}</dd>
                </div>
            </dl>
            <p v-if="selectedRow?.is_header" class="hint header-hint">Una cuenta mayor no recibe asientos directos: sus cifras suman las de sus cuentas hoja.</p>

            <template #actions>
                <button v-if="selectedRow && !selectedRow.is_header" type="button" class="btn btn-primary" @click="showMovements">
                    <ScrollTextIcon /> Ver movimientos
                </button>
            </template>
        </DetailModal>

        <LedgerPanel
            :open="ledger.open"
            dimension="account"
            :owner-id="ledger.ownerId"
            :owner-label="ledger.ownerLabel"
            @close="closeLedger"
        />
    </AppLayout>
</template>

<style scoped>
/* Seis columnas que tienen que caber en los ~720px que deja la barra
   lateral a 1025px (CLAUDE.md secc. 20): letra y relleno un poco más
   ajustados que en un listado, y la descripción corta una palabra larga
   antes que empujar la tabla de lado. */
table { font-size: 0.82rem; }
th, td { padding: 0.55rem 0.65rem; }
.code-cell { white-space: nowrap; font-variant-numeric: tabular-nums; }
/* La jerarquía del catálogo: cada nivel, un poco más adentro. */
.desc-cell {
    padding-left: calc(0.65rem + var(--depth, 0) * 0.75rem);
    overflow-wrap: anywhere;
}
.is-header td { font-weight: 700; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; color: var(--color-primary); }
.num-value { font-variant-numeric: tabular-nums; }
.header-hint { margin: 0.9rem 0 0; }

@media screen and (max-width: 1024px) {
    .desc-cell { padding-left: 0; }
    .total-label { text-align: left; }
}
</style>
