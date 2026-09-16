<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

defineProps({
    documents: { type: Array, default: () => [] },
    documentTypeLabels: { type: Object, default: () => ({}) },
    saleConditionLabels: { type: Object, default: () => ({}) },
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Comprobantes electrónicos" />

    <AppLayout title="Comprobantes electrónicos">
        <DocumentToolbar can-create @new="router.get(route('sales-documents.create'))" />

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ documents.length }} comprobante(s)</span>
                <Link :href="route('sales-documents.create')" class="btn btn-primary">+ Nueva factura</Link>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Consecutivo</th>
                            <th>Cliente</th>
                            <th>Condición</th>
                            <th>Vence</th>
                            <th class="right">Total</th>
                            <th>ERP</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in documents" :key="d.id" class="clickable-row"
                            @click="router.get(route('sales-documents.show', d.id))"
                        >
                            <td class="num">{{ d.posting_date }}</td>
                            <td><span class="badge badge-neutral">{{ d.fiscal_document_type }}</span></td>
                            <td class="num code-cell">{{ d.consecutive }}</td>
                            <td>{{ d.business_partner ? `${d.business_partner.code} — ${d.business_partner.name}` : 'Consumidor final' }}</td>
                            <td class="muted small">{{ saleConditionLabels[d.sale_condition] }}</td>
                            <td class="num muted small">{{ d.due_date ?? '—' }}</td>
                            <td class="num right">{{ d.currency?.code }} {{ money(d.total_document) }}</td>
                            <td class="muted small">
                                <span v-if="d.journal_entry_id">asiento</span>
                                <span v-if="d.inventory_document_id"> · stock</span>
                            </td>
                            <td>
                                <span class="badge" :class="d.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                                    {{ d.status === 'posted' ? 'Emitido' : 'Anulado' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!documents.length">
                            <td colspan="9" class="muted empty-row">Todavía no se ha emitido ningún comprobante.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--color-border);
}

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: var(--color-primary-soft); }
</style>
