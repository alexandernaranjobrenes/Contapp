<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeftIcon, EyeIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';

const props = defineProps({
    order: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
});

const page = usePage();

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function cancel() {
    confirmAction({
        title: `Cancelar la orden ${props.order.number}`,
        message: 'La orden deja de contar como mercancía en camino. No se puede deshacer.',
        confirmLabel: 'Cancelar orden',
        danger: true,
        onConfirm: () => router.post(route('purchase-orders.cancel', props.order.id), {}, { preserveScroll: true }),
    });
}

function close() {
    confirmAction({
        title: `Cerrar la orden ${props.order.number}`,
        message: 'Lo recibido queda registrado y el saldo pendiente se da por no recibido: deja de contar como en camino.',
        confirmLabel: 'Cerrar con saldo',
        danger: true,
        onConfirm: () => router.post(route('purchase-orders.close', props.order.id), {}, { preserveScroll: true }),
    });
}

// Ficha de una recepción (CLAUDE.md secc. 20): el enlace a su movimiento.
const selectedReceiptId = ref(null);
const selectedReceipt = computed(() => props.receipts.find((r) => r.id === selectedReceiptId.value) ?? null);
</script>

<template>
    <Head :title="'Orden de compra ' + order.number" />

    <AppLayout :title="'Orden de compra ' + order.number">
        <div class="view-toolbar">
            <Link :href="route('purchase-orders.index')" class="btn btn-ghost"><ArrowLeftIcon /> Órdenes de compra</Link>
            <div class="view-actions">
                <button v-if="order.is_pending && !order.has_receipts" type="button" class="btn btn-ghost btn-danger-text" @click="cancel">
                    Cancelar orden
                </button>
                <button v-if="order.is_pending" type="button" class="btn btn-ghost" @click="close">
                    Cerrar con saldo
                </button>
            </div>
        </div>

        <div v-if="page.props.errors?.order" class="flash flash-error">{{ page.props.errors.order }}</div>

        <div class="card summary">
            <div><span class="muted small">Proveedor</span><strong>{{ order.supplier }}</strong></div>
            <div><span class="muted small">Fecha</span><strong>{{ order.order_date }}</strong></div>
            <div><span class="muted small">Esperada</span><strong>{{ order.expected_date ?? '—' }}</strong></div>
            <div><span class="muted small">Estado</span><strong>{{ order.status_label }}</strong></div>
            <div v-if="order.description">
                <span class="muted small">Descripción</span><strong>{{ order.description }}</strong>
            </div>
        </div>

        <p v-if="order.has_receipts && order.is_pending" class="hint">
            Esta orden ya recibió mercancía, así que no se puede cancelar. Si el proveedor no va a entregar el resto,
            cerrala con saldo: lo recibido queda registrado y lo pendiente deja de contar como en camino.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th class="num">Ordenado</th>
                            <th class="num">Recibido</th>
                            <th class="num">Pendiente</th>
                            <th class="num">Costo pactado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in lines" :key="l.line_number">
                            <td><strong class="code">{{ l.item_code }}</strong> — {{ l.item_name }}</td>
                            <td data-label="Almacén">{{ l.warehouse_code }}</td>
                            <td data-label="Ordenado" class="num">{{ quantity(l.quantity) }}</td>
                            <td data-label="Recibido" class="num">{{ quantity(l.quantity_received) }}</td>
                            <td data-label="Pendiente" class="num" :class="l.pending > 0 ? 'pending' : 'done'">{{ quantity(l.pending) }}</td>
                            <td data-label="Costo pactado" class="num muted">{{ money(l.unit_cost_local) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <template v-if="receipts.length">
            <h2 class="block-title">Recepciones contra esta orden</h2>

            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in receipts"
                                :key="r.id"
                                class="clickable-row"
                                tabindex="0"
                                @click="selectedReceiptId = r.id"
                                @keydown.enter="selectedReceiptId = r.id"
                                @keydown.space.prevent="selectedReceiptId = r.id"
                            >
                                <td class="code">{{ r.posting_date }}</td>
                                <td data-label="Estado">
                                    <span class="badge" :class="r.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                                        {{ r.status === 'posted' ? 'Contabilizada' : 'Anulada' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <DetailModal :open="!!selectedReceipt" :title="selectedReceipt ? `Recepción del ${selectedReceipt.posting_date}` : ''" @close="selectedReceiptId = null">
            <template #badge>
                <span v-if="selectedReceipt" class="badge" :class="selectedReceipt.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                    {{ selectedReceipt.status === 'posted' ? 'Contabilizada' : 'Anulada' }}
                </span>
            </template>

            <p class="muted small">Entrada por compra registrada contra la orden {{ order.number }}.</p>

            <template #actions>
                <Link v-if="selectedReceipt" :href="route('inventory-movements.show', selectedReceipt.id)" class="btn btn-primary"><EyeIcon /> Ver movimiento</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.summary { display: flex; gap: 0.75rem 2rem; flex-wrap: wrap; padding: 1rem 1.25rem; margin-bottom: 0.9rem; }
.summary div { display: flex; flex-direction: column; min-width: 0; }
.pending { color: var(--color-warning); font-weight: 600; }
.done { color: var(--color-text-muted); }
</style>
