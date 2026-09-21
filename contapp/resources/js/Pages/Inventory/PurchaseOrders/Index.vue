<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
});

const status = ref(props.filters?.status ?? '');
const rows = computed(() => props.orders.data ?? []);

function applyFilters() {
    router.get(route('purchase-orders.index'), {
        status: status.value === '' ? undefined : status.value,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

function badgeClass(s) {
    if (s === 'received') return 'badge-success';
    if (s === 'cancelled' || s === 'closed') return 'badge-neutral';
    if (s === 'partially_received') return 'badge-warning';
    return 'badge-info';
}
</script>

<template>
    <Head title="Órdenes de compra" />

    <AppLayout title="Órdenes de compra">
        <template #actions>
            <select v-model="status" class="search-input" @change="applyFilters">
                <option value="">Todos los estados</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
            <Link :href="route('purchase-orders.create')" class="btn btn-primary">+ Nueva orden</Link>
        </template>

        <p class="hint">
            Una orden de compra es un <strong>compromiso, no un hecho económico</strong>: no genera asiento ni
            consecutivo fiscal. Lo único que cambia es cuánta mercancía queda declarada como en camino. El pasivo
            nace con la entrada por compra y su factura.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ orders.total }} orden(es)</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Fecha</th>
                        <th>Proveedor</th>
                        <th>Esperada</th>
                        <th class="num">Líneas</th>
                        <th>Estado</th>
                        <th>Descripción</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in rows" :key="o.id">
                        <td class="num">
                            <Link :href="route('purchase-orders.show', o.id)" class="link">{{ o.number }}</Link>
                        </td>
                        <td class="num">{{ o.order_date }}</td>
                        <td>{{ o.supplier }}</td>
                        <td class="num muted">{{ o.expected_date ?? '—' }}</td>
                        <td class="num">{{ o.lines_count }}</td>
                        <td><span class="badge" :class="badgeClass(o.status)">{{ o.status_label }}</span></td>
                        <td class="muted small">{{ o.description ?? '—' }}</td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="7" class="muted empty-row">
                            {{ filters.status ? 'Ninguna orden en ese estado.' : 'Todavía no hay órdenes de compra.' }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <nav v-if="orders.links.length > 3" class="pagination">
                <Link
                    v-for="(link, i) in orders.links"
                    :key="i"
                    :href="link.url ?? '#'"
                    class="page-link"
                    :class="{ active: link.active, disabled: !link.url }"
                    v-html="link.label"
                />
            </nav>
        </div>
    </AppLayout>
</template>

<style scoped>
.num { text-align: right; }
.pagination { display: flex; gap: 0.25rem; padding: 0.75rem 1.1rem; flex-wrap: wrap; }
.page-link { padding: 0.3rem 0.6rem; border-radius: var(--radius-sm); font-size: 0.78rem; text-decoration: none; color: var(--color-text-muted); }
.page-link.active { background: var(--color-primary); color: #fff; }
.page-link.disabled { opacity: 0.4; pointer-events: none; }
</style>
