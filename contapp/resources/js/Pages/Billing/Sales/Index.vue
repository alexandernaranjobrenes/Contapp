<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { ArrowRightIcon, DownloadIcon, EyeIcon, PlusIcon } from '@lucide/vue';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    documentTypeLabels: { type: Object, default: () => ({}) },
    saleConditionLabels: { type: Object, default: () => ({}) },
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function customer(d) {
    return d.business_partner ? `${d.business_partner.code} — ${d.business_partner.name}` : 'Consumidor final';
}

const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (! q) return props.documents;

    return props.documents.filter((d) => String(d.consecutive).includes(q) || customer(d).toLowerCase().includes(q));
});

// Ficha del comprobante (CLAUDE.md secc. 20): el resumen, el XML y la nota
// de crédito; el detalle de líneas está en su pantalla.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.documents);

// Una nota de crédito no se corrige con otra nota: lo que corresponde ahí es
// una nota de débito, que es otro documento.
const canCredit = computed(() => selected.value && selected.value.fiscal_document_type !== '03' && selected.value.status === 'posted');
</script>

<template>
    <Head title="Comprobantes electrónicos" />

    <AppLayout title="Comprobantes electrónicos">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('sales-documents.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar consecutivo o cliente..." aria-label="Buscar comprobante">
                <span class="muted small">{{ filtered.length }} comprobante(s)</span>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Consecutivo</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th class="num">Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in filtered"
                            :key="d.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(d)"
                            @keydown.enter="openDetail(d)"
                            @keydown.space.prevent="openDetail(d)"
                        >
                            <td class="code-cell">
                                <span class="badge badge-neutral" :title="documentTypeLabels[d.fiscal_document_type]">{{ d.fiscal_document_type }}</span>
                                {{ d.consecutive }}
                            </td>
                            <td data-label="Fecha" class="code-cell">{{ d.posting_date }}</td>
                            <td data-label="Cliente">{{ customer(d) }}</td>
                            <td data-label="Total" class="num">{{ d.currency?.code }} {{ money(d.total_document) }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="d.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                                    {{ d.status === 'posted' ? 'Emitido' : 'Anulado' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="5" class="muted empty-row">
                                {{ documents.length ? 'Ningún comprobante coincide con la búsqueda.' : 'Todavía no se ha emitido ningún comprobante.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal
            :open="!!selected"
            :title="selected ? `${documentTypeLabels[selected.fiscal_document_type] ?? 'Comprobante'} ${selected.consecutive}` : ''"
            @close="closeDetail"
        >
            <template #badge>
                <span v-if="selected" class="badge" :class="selected.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'posted' ? 'Emitido' : 'Anulado' }}
                </span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Fecha</dt>
                    <dd>{{ selected.posting_date }}</dd>
                </div>
                <div>
                    <dt>Cliente</dt>
                    <dd>{{ customer(selected) }}</dd>
                </div>
                <div>
                    <dt>Condición</dt>
                    <dd>{{ saleConditionLabels[selected.sale_condition] }}</dd>
                </div>
                <div>
                    <dt>Vence</dt>
                    <dd>{{ selected.due_date ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Total</dt>
                    <dd>{{ selected.currency?.code }} {{ money(selected.total_document) }}</dd>
                </div>
                <div>
                    <dt>En el ERP</dt>
                    <dd>
                        {{ [selected.journal_entry_id ? 'asiento' : null, selected.inventory_document_id ? 'salida de inventario' : null].filter(Boolean).join(' · ') || '—' }}
                    </dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <a :href="route('sales-documents.xml', selected.id)" class="btn btn-ghost"><DownloadIcon /> Descargar XML</a>
                    <Link v-if="canCredit" :href="route('sales-documents.create', { correct: selected.id })" class="btn btn-ghost">
                        <ArrowRightIcon /> Nota de crédito
                    </Link>
                    <Link :href="route('sales-documents.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver comprobante</Link>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
