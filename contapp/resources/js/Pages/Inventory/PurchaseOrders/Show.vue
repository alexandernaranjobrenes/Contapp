<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
    if (! confirm('¿Cancelar la orden ' + props.order.number + '?')) return;

    router.post(route('purchase-orders.cancel', props.order.id), {}, { preserveScroll: true });
}

function close() {
    if (! confirm('¿Cerrar la orden ' + props.order.number + ' dando por no recibido el saldo pendiente?')) return;

    router.post(route('purchase-orders.close', props.order.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="'Orden de compra ' + order.number" />

    <AppLayout :title="'Orden de compra ' + order.number">
        <template #actions>
            <button v-if="order.is_pending && !order.has_receipts" type="button" class="btn btn-ghost" @click="cancel">
                Cancelar orden
            </button>
            <button v-if="order.is_pending" type="button" class="btn btn-ghost" @click="close">
                Cerrar con saldo
            </button>
            <Link :href="route('purchase-orders.index')" class="btn btn-ghost">Volver</Link>
        </template>

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
                        <td><strong class="num">{{ l.item_code }}</strong> — {{ l.item_name }}</td>
                        <td>{{ l.warehouse_code }}</td>
                        <td class="num">{{ quantity(l.quantity) }}</td>
                        <td class="num">{{ quantity(l.quantity_received) }}</td>
                        <td class="num" :class="l.pending > 0 ? 'pending' : 'done'">{{ quantity(l.pending) }}</td>
                        <td class="num muted">{{ money(l.unit_cost_local) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 v-if="receipts.length" class="section-title">Recepciones contra esta orden</h2>

        <div v-if="receipts.length" class="card">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in receipts" :key="r.id">
                        <td class="num">{{ r.posting_date }}</td>
                        <td>{{ r.status === 'posted' ? 'Contabilizada' : 'Anulada' }}</td>
                        <td>
                            <Link :href="route('inventory-movements.show', r.id)" class="link">Ver movimiento</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.num { text-align: right; }
.summary { display: flex; gap: 2rem; flex-wrap: wrap; }
.summary div { display: flex; flex-direction: column; }
.pending { color: #a04000; font-weight: 600; }
.done { color: var(--color-text-muted); }
</style>
