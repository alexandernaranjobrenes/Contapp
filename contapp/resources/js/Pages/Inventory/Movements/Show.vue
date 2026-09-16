<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    document: { type: Object, required: true },
    operationLabel: { type: String, default: '' },
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function movementOf(line) {
    return line.stock_journals?.[0] ?? null;
}

const totalLocal = props.document.lines.reduce(
    (sum, line) => sum + Number(movementOf(line)?.total_cost_local ?? 0), 0
);
</script>

<template>
    <Head :title="`Movimiento ${operationLabel}`" />

    <AppLayout :title="operationLabel">
        <div class="card summary">
            <div><span class="muted small">Tipo de documento</span><strong>{{ document.document_type?.code }}</strong></div>
            <div><span class="muted small">Fecha de contabilización</span><strong>{{ document.posting_date }}</strong></div>
            <div><span class="muted small">Fecha del documento</span><strong>{{ document.document_date }}</strong></div>
            <div>
                <span class="muted small">Asiento generado</span>
                <Link :href="route('journal-entries.show', document.journal_entry_id)" class="link">
                    #{{ document.journal_entry?.document_number }}
                </Link>
            </div>
            <div v-if="document.business_partner">
                <span class="muted small">Proveedor</span>
                <strong>{{ document.business_partner.code }} — {{ document.business_partner.name }}</strong>
            </div>
            <div v-if="document.operation === 'purchase_receipt'">
                <span class="muted small">Cuenta puente GR/IR</span>
                <Link
                    v-if="document.invoice_journal_entry_id"
                    :href="route('journal-entries.show', document.invoice_journal_entry_id)"
                    class="link"
                >
                    Liquidada por factura #{{ document.invoice_journal_entry?.document_number }}
                </Link>
                <span v-else class="badge badge-warning">Pendiente de facturar</span>
            </div>
            <div>
                <span class="muted small">Estado</span>
                <span class="badge" :class="document.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                    {{ document.status === 'posted' ? 'Contabilizado' : 'Anulado' }}
                </span>
            </div>
        </div>

        <p v-if="document.description" class="hint">{{ document.description }}</p>

        <div class="card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th>Mov.</th>
                            <th class="right">Cantidad</th>
                            <th class="right">Costo unitario</th>
                            <th class="right">Total (LC)</th>
                            <th class="right">Total (FC)</th>
                            <th class="right">Saldo tras el mov.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in document.lines" :key="line.id">
                            <td class="num">{{ line.line_number }}</td>
                            <td>{{ line.item?.code }} — {{ line.item?.name }}</td>
                            <td class="code-cell">{{ line.warehouse?.code }}</td>
                            <td>
                                <span class="badge" :class="movementOf(line)?.direction === 'in' ? 'badge-success' : 'badge-warning'">
                                    {{ movementOf(line)?.direction === 'in' ? 'Entra' : 'Sale' }}
                                </span>
                            </td>
                            <td class="num right">{{ quantity(line.quantity) }}</td>
                            <td class="num right">{{ money(line.unit_cost_local) }}</td>
                            <td class="num right">{{ money(movementOf(line)?.total_cost_local) }}</td>
                            <td class="num right">{{ money(movementOf(line)?.total_cost_foreign) }}</td>
                            <td class="num right muted">{{ quantity(movementOf(line)?.balance_quantity) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="right"><strong>Total</strong></td>
                            <td class="num right"><strong>{{ money(totalLocal) }}</strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="hint">
            Cada línea dejó su huella en el kardex y en el asiento contable, que se generaron en la misma transacción.
        </p>
    </AppLayout>
</template>

<style scoped>
.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
}

.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0.75rem 0 0; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }
</style>
