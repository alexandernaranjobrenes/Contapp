<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { useRecordDetail } from '../../Utils/recordDetail';
import { formatMoney } from '../../Utils/money';
import { DownloadIcon } from '@lucide/vue';

const props = defineProps({
    bankAccounts: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
});

const MONTHS = [
    { value: 1, label: 'Enero' }, { value: 2, label: 'Febrero' }, { value: 3, label: 'Marzo' },
    { value: 4, label: 'Abril' }, { value: 5, label: 'Mayo' }, { value: 6, label: 'Junio' },
    { value: 7, label: 'Julio' }, { value: 8, label: 'Agosto' }, { value: 9, label: 'Septiembre' },
    { value: 10, label: 'Octubre' }, { value: 11, label: 'Noviembre' }, { value: 12, label: 'Diciembre' },
];

const bankAccountId = ref(props.filters.bank_account_id);
const year = ref(props.filters.year);
const month = ref(props.filters.month);

function applyFilter() {
    router.get(route('bank-reconciliation-report.index'), {
        bank_account_id: bankAccountId.value || undefined,
        year: year.value,
        month: month.value,
    }, { preserveState: true });
}

function exportUrl() {
    return route('bank-reconciliation-report.export', {
        bank_account_id: bankAccountId.value || undefined,
        year: year.value,
        month: month.value,
    });
}

// Detalle de la conciliación (CLAUDE.md secc. 20): la tabla muestra el corte,
// si cuadra y los dos saldos ajustados; las partidas de conciliación y los
// movimientos línea por línea (depósitos en tránsito / cheques no pagados,
// igual que el XLSX) están en la ficha.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.rows);
</script>

<template>
    <Head title="Reporte de conciliaciones bancarias" />

    <AppLayout title="Reporte de conciliaciones bancarias">
        <div class="view-toolbar">
            <div v-if="bankAccountId" class="view-actions">
                <a :href="exportUrl()" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
            </div>
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Cuenta bancaria</span>
                    <select v-model="bankAccountId">
                        <option :value="null">Seleccione una cuenta —</option>
                        <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank_name }} — {{ a.account_number }}</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Año</span>
                    <input v-model.number="year" type="number" min="2000" max="2100" step="1" class="year-input">
                </label>
                <label class="filter-field">
                    <span>Mes</span>
                    <select v-model.number="month">
                        <option v-for="m in MONTHS" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>
        </div>

        <div v-if="!bankAccountId" class="card empty-card">
            Seleccioná una cuenta bancaria, año y mes, y hacé clic en "Consultar".
        </div>

        <div v-else class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Corte</th>
                            <th>Estado</th>
                            <th class="num">Saldo banco ajustado</th>
                            <th class="num">Saldo libros ajustado</th>
                            <th>Cuadra</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(row)"
                            @keydown.enter="openDetail(row)"
                            @keydown.space.prevent="openDetail(row)"
                        >
                            <td>{{ row.cutoff_date }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="row.status === 'completed' ? 'badge-success' : 'badge-warning'">
                                    {{ row.status_label }}
                                </span>
                            </td>
                            <td data-label="Saldo banco ajustado" class="num">{{ formatMoney(row.adjusted_bank_balance) }}</td>
                            <td data-label="Saldo libros ajustado" class="num">{{ formatMoney(row.adjusted_book_balance) }}</td>
                            <td data-label="Cuadra">
                                <span class="diff" :class="row.is_balanced ? 'ok' : 'off'">{{ row.is_balanced ? 'Sí' : 'No' }}</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="muted empty-row">Sin conciliaciones en el período seleccionado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" wide :title="selected ? `Conciliación al ${selected.cutoff_date}` : ''" @close="closeDetail">
            <template #badge>
                <template v-if="selected">
                    <span class="badge" :class="selected.status === 'completed' ? 'badge-success' : 'badge-warning'">{{ selected.status_label }}</span>
                    <span class="badge" :class="selected.is_balanced ? 'badge-success' : 'badge-danger'">{{ selected.is_balanced ? 'Cuadra' : 'No cuadra' }}</span>
                </template>
            </template>

            <template v-if="selected">
                <div class="summary-grid">
                    <dl class="summary">
                        <dt>Saldo según banco</dt><dd>{{ formatMoney(selected.bank_balance) }}</dd>
                        <dt>Depósitos no acreditados</dt><dd>{{ formatMoney(selected.unrecorded_deposits) }}</dd>
                        <dt>Cheques no pagados</dt><dd>{{ formatMoney(selected.unpaid_checks) }}</dd>
                        <dt class="total">Saldo banco ajustado</dt><dd class="total">{{ formatMoney(selected.adjusted_bank_balance) }}</dd>
                    </dl>
                    <dl class="summary">
                        <dt>Saldo de libros</dt><dd>{{ formatMoney(selected.book_balance) }}</dd>
                        <dt>Créd. banco no registrados</dt><dd>{{ formatMoney(selected.unrecorded_bank_credits) }}</dd>
                        <dt>Déb. banco no registrados</dt><dd>{{ formatMoney(selected.unrecorded_bank_debits) }}</dd>
                        <dt class="total">Saldo libros ajustado</dt><dd class="total">{{ formatMoney(selected.adjusted_book_balance) }}</dd>
                    </dl>
                </div>
                <p class="muted small">Generada por {{ selected.created_by_name ?? '—' }}.</p>

                <h4 class="block-title">Movimientos</h4>
                <div class="table-responsive">
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Documento</th>
                                <th>Descripción</th>
                                <th class="num">Débito</th>
                                <th class="num">Crédito</th>
                                <th>Tipo</th>
                                <th>En banco</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in selected.lines"
                                :key="`${selected.id}-${line.document}-${line.date}-${line.debit}-${line.credit}`"
                                :class="{ pending: !line.matched_in_bank }"
                            >
                                <td>{{ line.date }}</td>
                                <td data-label="Documento">
                                    {{ line.document }}
                                    <span v-if="line.reference_document" class="muted small ref">
                                        Ref. {{ line.reference_document }}<template v-if="line.reference_document_date"> ({{ line.reference_document_date }})</template>
                                    </span>
                                </td>
                                <td data-label="Descripción">{{ line.description }}</td>
                                <td data-label="Débito" class="num">{{ line.debit !== '0.00' ? formatMoney(line.debit) : '' }}</td>
                                <td data-label="Crédito" class="num">{{ line.credit !== '0.00' ? formatMoney(line.credit) : '' }}</td>
                                <td data-label="Tipo">{{ line.type === 'deposito' ? 'Depósito' : 'Cheque' }}</td>
                                <td data-label="En banco">{{ line.matched_in_bank ? 'Sí' : 'No' }}</td>
                            </tr>
                            <tr v-if="!selected.lines.length">
                                <td colspan="7" class="muted empty-row">Sin movimientos.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.84rem; }
.year-input { width: 6.5rem; }
.empty-card { padding: 1.5rem; color: var(--color-text-muted); }

.diff { font-weight: 700; }
.diff.ok { color: var(--color-success); }
.diff.off { color: var(--color-danger); }

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr));
    gap: 0.75rem 1.5rem;
    margin-bottom: 0.5rem;
}
.summary { margin: 0; display: grid; grid-template-columns: 1fr auto; row-gap: 0.35rem; column-gap: 1rem; font-size: 0.84rem; }
.summary dt { color: var(--color-text-muted); }
.summary dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
.summary .total { font-weight: 800; color: var(--color-text); border-top: 1px solid var(--color-border); padding-top: 0.35rem; }

.detail-table { font-size: 0.8rem; }
.detail-table tr.pending td { background: var(--color-warning-soft); }
.ref { display: block; }

@media screen and (max-width: 1024px) {
    .detail-table tr.pending td { background: none; }
    .table-responsive tbody tr.pending { background: var(--color-warning-soft); }
}
</style>
