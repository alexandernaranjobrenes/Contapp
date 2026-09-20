<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    orders: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
});

const filter = ref('');

const visible = computed(() =>
    filter.value ? props.orders.filter((o) => o.status === filter.value) : props.orders
);

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const badgeClass = {
    open: 'badge-warning',
    invoiced: 'badge-success',
    cancelled: 'badge-neutral',
};
</script>

<template>
    <Head title="Órdenes de pedido" />

    <AppLayout title="Órdenes de pedido">
        <template #actions>
            <Link :href="route('sales-orders.create')" class="btn btn-primary">Nuevo pedido</Link>
        </template>

        <p class="hint">
            Un pedido es un compromiso con el cliente, no un hecho económico: <strong>no genera asiento</strong>.
            Lo único que cambia es que la mercancía prometida queda apartada y deja de estar disponible para
            venderle a otro, hasta que se facture o se cancele.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ visible.length }} pedido(s)</span>
                <select v-model="filter" class="filter">
                    <option value="">Todos los estados</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Fecha</th>
                            <th>Entrega</th>
                            <th>Cliente</th>
                            <th class="right">Líneas</th>
                            <th class="right">Apartado</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in visible" :key="order.id">
                            <td class="num">
                                <Link :href="route('sales-orders.show', order.id)" class="link">{{ order.number }}</Link>
                            </td>
                            <td class="num">{{ order.order_date }}</td>
                            <td class="num muted">{{ order.delivery_date ?? '—' }}</td>
                            <td>{{ order.customer }}</td>
                            <td class="num right">{{ order.lines_count }}</td>
                            <td class="num right">{{ order.status === 'open' ? quantity(order.pending) : '—' }}</td>
                            <td><span class="badge" :class="badgeClass[order.status]">{{ order.status_label }}</span></td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="7" class="muted empty-row">No hay pedidos con ese estado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1.25rem; }
.filter { font-size: 0.82rem; padding: 0.3rem 0.5rem; }
.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }
</style>
