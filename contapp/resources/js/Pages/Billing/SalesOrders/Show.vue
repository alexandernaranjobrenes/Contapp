<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { confirmAction } from '../../../Utils/confirm';
import { ArrowLeftIcon, ArrowRightIcon } from '@lucide/vue';

const props = defineProps({
    order: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
});

const page = usePage();

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const pending = computed(() => props.lines.reduce((sum, line) => sum + Number(line.pending), 0));
const isOpen = computed(() => props.order.status === 'open');

function cancelOrder() {
    confirmAction({
        title: 'Cancelar la orden de pedido',
        message: 'La mercancía que sigue apartada volverá a quedar disponible para otros clientes. Lo ya facturado no se toca: esa mercancía salió y tiene su comprobante. El pedido queda marcado como cancelado.',
        confirmLabel: 'Cancelar el pedido',
        danger: true,
        onConfirm: () => router.post(route('sales-orders.cancel', props.order.id)),
    });
}

const badgeClass = {
    open: 'badge-warning',
    invoiced: 'badge-success',
    cancelled: 'badge-neutral',
};
</script>

<template>
    <Head :title="`Pedido ${order.number}`" />

    <AppLayout :title="`Pedido ${order.number}`">
        <div class="view-toolbar">
            <Link :href="route('sales-orders.index')" class="btn btn-ghost"><ArrowLeftIcon /> Órdenes de pedido</Link>
            <div v-if="isOpen" class="view-actions">
                <button type="button" class="btn btn-ghost btn-danger-text" @click="cancelOrder">Cancelar pedido</button>
                <Link :href="route('sales-documents.create', { order: order.id })" class="btn btn-primary">
                    Copiar a <ArrowRightIcon /> Factura de venta
                </Link>
            </div>
        </div>

        <div v-if="page.props.errors?.order" class="flash flash-error">{{ page.props.errors.order }}</div>

        <div class="card summary">
            <div><span class="muted small">Cliente</span><strong>{{ order.customer }}</strong></div>
            <div><span class="muted small">Fecha del pedido</span><strong>{{ order.order_date }}</strong></div>
            <div><span class="muted small">Entrega</span><strong>{{ order.delivery_date ?? '—' }}</strong></div>
            <div>
                <span class="muted small">Estado</span>
                <span class="badge" :class="badgeClass[order.status]">{{ order.status_label }}</span>
            </div>
            <div>
                <span class="muted small">Apartado ahora</span>
                <strong class="num">{{ isOpen ? quantity(pending) : '—' }}</strong>
            </div>
        </div>

        <p class="hint">
            <template v-if="isOpen">
                Este pedido tiene <strong>{{ quantity(pending) }}</strong> unidades apartadas: no se le pueden
                vender ni trasladar a nadie más. La reserva se libera al facturar lo entregado o al cancelar.
            </template>
            <template v-else-if="order.status === 'invoiced'">
                El pedido se cumplió por completo: la mercancía salió con su factura y ya no hay nada apartado.
            </template>
            <template v-else>
                El pedido fue cancelado: lo que seguía apartado volvió a quedar disponible.
            </template>
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th class="num">Pedido</th>
                            <th class="num">Facturado</th>
                            <th class="num">Apartado</th>
                            <th class="num">Precio pactado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in lines" :key="line.id">
                            <td>
                                <span class="muted num">{{ line.line_number }}.</span> {{ line.item }}
                                <span class="muted small block">Bodega {{ line.warehouse_code }}</span>
                            </td>
                            <td data-label="Pedido" class="num">{{ quantity(line.quantity) }}</td>
                            <td data-label="Facturado" class="num">{{ quantity(line.quantity_invoiced) }}</td>
                            <td data-label="Apartado" class="num">{{ isOpen ? quantity(line.pending) : '—' }}</td>
                            <td data-label="Precio pactado" class="num">{{ money(line.unit_price) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>Facturas emitidas contra este pedido</strong></div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Consecutivo</th>
                            <th>Fecha</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices" :key="invoice.id">
                            <td class="num">
                                <Link :href="route('sales-documents.show', invoice.id)" class="link">
                                    {{ invoice.consecutive }}
                                </Link>
                            </td>
                            <td data-label="Fecha" class="num">{{ invoice.posting_date }}</td>
                            <td data-label="Total" class="num">{{ money(invoice.total) }}</td>
                        </tr>
                        <tr v-if="!invoices.length">
                            <td colspan="3" class="muted empty-row">Todavía no se ha facturado nada de este pedido.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.summary { display: flex; flex-wrap: wrap; gap: 0.75rem 1.5rem; padding: 1rem 1.25rem; margin-bottom: 0.75rem; }
.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

.card-header { padding: 0.75rem 1.25rem; }
.card + .card { margin-top: 0.75rem; }
table { font-size: 0.85rem; }
.num { font-variant-numeric: tabular-nums; }
.block { display: block; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }
</style>
