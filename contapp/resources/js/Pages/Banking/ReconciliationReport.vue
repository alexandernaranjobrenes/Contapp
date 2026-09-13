<script setup>
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatMoney } from '../../Utils/money';

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

// Detalle línea por línea (depósitos en tránsito / cheques no pagados,
// igual que el XLSX): plegado por defecto, un clic en la fila lo despliega.
const expanded = reactive(new Set());

function toggleExpanded(rowId) {
    if (expanded.has(rowId)) expanded.delete(rowId);
    else expanded.add(rowId);
}
</script>

<template>
    <Head title="Reporte de conciliaciones bancarias" />

    <AppLayout title="Reporte de conciliaciones bancarias">
        <template #actions>
            <a v-if="bankAccountId" :href="exportUrl()" class="btn btn-ghost">Exportar XLSX</a>
        </template>

        <div class="card filter-bar">
            <div class="field">
                <label>Cuenta bancaria</label>
                <select v-model="bankAccountId">
                    <option :value="null">Seleccione una cuenta —</option>
                    <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.bank_name }} — {{ a.account_number }}</option>
                </select>
            </div>
            <div class="field">
                <label>Año</label>
                <input v-model.number="year" type="number" min="2000" max="2100" step="1">
            </div>
            <div class="field">
                <label>Mes</label>
                <select v-model.number="month">
                    <option v-for="m in MONTHS" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
            </div>
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
        </div>

        <div v-if="!bankAccountId" class="card empty-card">
            Seleccioná una cuenta bancaria, año y mes, y hacé clic en "Consultar".
        </div>

        <div v-else class="card">
            <table>
                <thead>
                    <tr>
                        <th></th>
                        <th>Corte</th>
                        <th>Estado</th>
                        <th class="num">Saldo banco</th>
                        <th class="num">Depósitos no acred.</th>
                        <th class="num">Cheques no pagados</th>
                        <th class="num">Saldo banco ajustado</th>
                        <th class="num">Saldo libros</th>
                        <th class="num">Créd. banco no reg.</th>
                        <th class="num">Déb. banco no reg.</th>
                        <th class="num">Saldo libros ajustado</th>
                        <th>Cuadra</th>
                        <th>Generada por</th>
                    </tr>
                </thead>
                <tbody v-for="row in rows" :key="row.id">
                    <tr class="selectable-row" @click="toggleExpanded(row.id)">
                        <td class="expand-cell">{{ expanded.has(row.id) ? '▾' : '▸' }}</td>
                        <td>{{ row.cutoff_date }}</td>
                        <td>
                            <span class="badge" :class="row.status === 'completed' ? 'badge-success' : 'badge-warning'">
                                {{ row.status_label }}
                            </span>
                        </td>
                        <td class="num">{{ formatMoney(row.bank_balance) }}</td>
                        <td class="num">{{ formatMoney(row.unrecorded_deposits) }}</td>
                        <td class="num">{{ formatMoney(row.unpaid_checks) }}</td>
                        <td class="num">{{ formatMoney(row.adjusted_bank_balance) }}</td>
                        <td class="num">{{ formatMoney(row.book_balance) }}</td>
                        <td class="num">{{ formatMoney(row.unrecorded_bank_credits) }}</td>
                        <td class="num">{{ formatMoney(row.unrecorded_bank_debits) }}</td>
                        <td class="num">{{ formatMoney(row.adjusted_book_balance) }}</td>
                        <td>
                            <span class="diff" :class="row.is_balanced ? 'ok' : 'off'">{{ row.is_balanced ? 'Sí' : 'No' }}</span>
                        </td>
                        <td>{{ row.created_by_name ?? '—' }}</td>
                    </tr>
                    <tr v-if="expanded.has(row.id)" class="detail-row">
                        <td colspan="13">
                            <table class="detail-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Documento</th>
                                        <th>Documento de referencia</th>
                                        <th>Fecha de documento</th>
                                        <th>Descripción</th>
                                        <th class="num">Débito</th>
                                        <th class="num">Crédito</th>
                                        <th>Tipo</th>
                                        <th>Confirmado en banco</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="line in row.lines" :key="`${row.id}-${line.document}-${line.date}-${line.debit}-${line.credit}`" :class="{ pending: !line.matched_in_bank }">
                                        <td>{{ line.date }}</td>
                                        <td>{{ line.document }}</td>
                                        <td>{{ line.reference_document ?? '—' }}</td>
                                        <td>{{ line.reference_document_date ?? '—' }}</td>
                                        <td class="desc-cell">{{ line.description }}</td>
                                        <td class="num">{{ line.debit !== '0.00' ? formatMoney(line.debit) : '' }}</td>
                                        <td class="num">{{ line.credit !== '0.00' ? formatMoney(line.credit) : '' }}</td>
                                        <td>{{ line.type === 'deposito' ? 'Depósito' : 'Cheque' }}</td>
                                        <td>{{ line.matched_in_bank ? 'Sí' : 'No' }}</td>
                                    </tr>
                                    <tr v-if="!row.lines.length">
                                        <td colspan="9" class="muted empty-row">Sin movimientos.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </tbody>
                <tbody v-if="!rows.length">
                    <tr>
                        <td colspan="13" class="muted empty-row">Sin conciliaciones en el período seleccionado.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.filter-bar {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
    color: var(--color-text);
}

table { font-size: 0.82rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.75rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
thead th.num { text-align: right; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.empty-card { padding: 1.5rem; color: var(--color-text-muted); }

.diff { font-weight: 700; }
.diff.ok { color: var(--color-success); }
.diff.off { color: var(--color-danger); }

.selectable-row { cursor: pointer; }
.selectable-row:hover { background: var(--color-primary-soft); }
.expand-cell { width: 1.5rem; text-align: center; color: var(--color-text-muted); }

.detail-row td { padding: 0.4rem 0.75rem 0.6rem; background: var(--color-surface-alt); }
.detail-table { width: 100%; font-size: 0.78rem; }
.detail-table th, .detail-table td { padding: 0.35rem 0.6rem; border-top: 1px solid var(--color-border); white-space: normal; }
.detail-table .desc-cell { max-width: 320px; }
.detail-table tr.pending { background: var(--color-warning-soft); }
</style>
