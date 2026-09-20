<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';

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

const confirmingCancel = ref(false);
const cancelling = ref(false);

function submitCancel() {
    cancelling.value = true;

    router.post(route('sales-orders.cancel', props.order.id), {}, {
        onFinish: () => { cancelling.value = false; confirmingCancel.value = false; },
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
        <template #actions>
            <button v-if="isOpen" type="button" class="btn btn-ghost" @click="confirmingCancel = true">
                Cancelar pedido
            </button>
            <Link v-if="isOpen" :href="route('sales-documents.create', { order: order.id })" class="btn btn-primary">
                Copiar a → Factura de venta
            </Link>
        </template>

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
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Artículo</th>
                            <th>Bodega</th>
                            <th class="right">Pedido</th>
                            <th class="right">Facturado</th>
                            <th class="right">Apartado</th>
                            <th class="right">Precio pactado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in lines" :key="line.id">
                            <td class="num">{{ line.line_number }}</td>
                            <td>{{ line.item }}</td>
                            <td class="num">{{ line.warehouse_code }}</td>
                            <td class="num right">{{ quantity(line.quantity) }}</td>
                            <td class="num right">{{ quantity(line.quantity_invoiced) }}</td>
                            <td class="num right">{{ isOpen ? quantity(line.pending) : '—' }}</td>
                            <td class="num right">{{ money(line.unit_price) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>Facturas emitidas contra este pedido</strong></div>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Consecutivo</th>
                            <th>Fecha</th>
                            <th class="right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices" :key="invoice.id">
                            <td class="num">
                                <Link :href="route('sales-documents.show', invoice.id)" class="link">
                                    {{ invoice.consecutive }}
                                </Link>
                            </td>
                            <td class="num">{{ invoice.posting_date }}</td>
                            <td class="num right">{{ money(invoice.total) }}</td>
                        </tr>
                        <tr v-if="!invoices.length">
                            <td colspan="3" class="muted empty-row">Todavía no se ha facturado nada de este pedido.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal
            :open="confirmingCancel"
            title="Cancelar la orden de pedido"
            message="La mercancía que sigue apartada volverá a quedar disponible para otros clientes. Lo ya facturado no se toca: esa mercancía salió y tiene su comprobante. El pedido queda marcado como cancelado."
            confirm-label="Cancelar el pedido"
            danger
            :processing="cancelling"
            @confirm="submitCancel"
            @cancel="confirmingCancel = false"
        />
    </AppLayout>
</template>

<style scoped>
.summary { display: flex; flex-wrap: wrap; gap: 1.5rem; padding: 1rem 1.25rem; margin-bottom: 0.75rem; }
.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.card-header { padding: 0.75rem 1.25rem; }
.card + .card { margin-top: 0.75rem; }
.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.25rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
</style>
