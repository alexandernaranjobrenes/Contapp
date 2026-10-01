<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { EyeIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';

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

// Ficha de la orden (CLAUDE.md secc. 20): el resumen; la orden completa, con
// sus líneas y acciones, está en «Ver orden».
const { selected, openDetail, closeDetail } = useRecordDetail(() => rows.value);
</script>

<template>
    <Head title="Órdenes de compra" />

    <AppLayout title="Órdenes de compra">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('purchase-orders.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <div class="view-filters">
                <select v-model="status" aria-label="Estado" @change="applyFilters">
                    <option value="">Todos los estados</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>
        </div>

        <p class="hint">
            Una orden de compra es un <strong>compromiso, no un hecho económico</strong>: no genera asiento ni
            consecutivo fiscal. Lo único que cambia es cuánta mercancía queda declarada como en camino. El pasivo
            nace con la entrada por compra y su factura.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="o in rows"
                            :key="o.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(o)"
                            @keydown.enter="openDetail(o)"
                            @keydown.space.prevent="openDetail(o)"
                        >
                            <td class="code-cell">{{ o.number }}</td>
                            <td data-label="Fecha" class="code-cell">{{ o.order_date }}</td>
                            <td data-label="Proveedor">{{ o.supplier }}</td>
                            <td data-label="Estado"><span class="badge" :class="badgeClass(o.status)">{{ o.status_label }}</span></td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="4" class="muted empty-row">
                                {{ filters.status ? 'Ninguna orden en ese estado.' : 'Todavía no hay órdenes de compra.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="orders.links.length > 3" class="pagination" aria-label="Páginas">
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

        <DetailModal :open="!!selected" :title="selected ? `Orden ${selected.number}` : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="badgeClass(selected.status)">{{ selected.status_label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div class="full">
                    <dt>Proveedor</dt>
                    <dd>{{ selected.supplier }}</dd>
                </div>
                <div>
                    <dt>Fecha</dt>
                    <dd>{{ selected.order_date }}</dd>
                </div>
                <div>
                    <dt>Entrega esperada</dt>
                    <dd>{{ selected.expected_date ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Líneas</dt>
                    <dd>{{ selected.lines_count }}</dd>
                </div>
                <div class="full">
                    <dt>Descripción</dt>
                    <dd>{{ selected.description ?? '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selected" :href="route('purchase-orders.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver orden</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
