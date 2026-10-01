<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { ArrowRightIcon, EyeIcon, PlusIcon } from '@lucide/vue';

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

// Ficha del pedido (CLAUDE.md secc. 20): abrirlo o pasarlo a factura.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.orders);
</script>

<template>
    <Head title="Órdenes de pedido" />

    <AppLayout title="Órdenes de pedido">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('sales-orders.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <div class="view-filters">
                <label class="filter-field">
                    <span>Estado</span>
                    <select v-model="filter">
                        <option value="">Todos los estados</option>
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                </label>
                <span class="muted small">{{ visible.length }} pedido(s)</span>
            </div>
        </div>

        <p class="hint">
            Un pedido es un compromiso con el cliente, no un hecho económico: <strong>no genera asiento</strong>.
            Lo único que cambia es que la mercancía prometida queda apartada y deja de estar disponible para
            venderle a otro, hasta que se facture o se cancele.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th class="num">Apartado</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="order in visible"
                            :key="order.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(order)"
                            @keydown.enter="openDetail(order)"
                            @keydown.space.prevent="openDetail(order)"
                        >
                            <td class="code-cell">{{ order.number }}</td>
                            <td data-label="Fecha" class="code-cell">{{ order.order_date }}</td>
                            <td data-label="Cliente">{{ order.customer }}</td>
                            <td data-label="Apartado" class="num">{{ order.status === 'open' ? quantity(order.pending) : '—' }}</td>
                            <td data-label="Estado"><span class="badge" :class="badgeClass[order.status]">{{ order.status_label }}</span></td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="5" class="muted empty-row">No hay pedidos con ese estado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `Pedido ${selected.number}` : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="badgeClass[selected.status]">{{ selected.status_label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Cliente</dt>
                    <dd>{{ selected.customer }}</dd>
                </div>
                <div>
                    <dt>Fecha del pedido</dt>
                    <dd>{{ selected.order_date }}</dd>
                </div>
                <div>
                    <dt>Entrega</dt>
                    <dd>{{ selected.delivery_date ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Líneas</dt>
                    <dd>{{ selected.lines_count }}</dd>
                </div>
                <div>
                    <dt>Apartado ahora</dt>
                    <dd>{{ selected.status === 'open' ? quantity(selected.pending) : '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <Link v-if="selected.status === 'open'" :href="route('sales-documents.create', { order: selected.id })" class="btn btn-ghost">
                        Copiar a <ArrowRightIcon /> Factura
                    </Link>
                    <Link :href="route('sales-orders.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver pedido</Link>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
