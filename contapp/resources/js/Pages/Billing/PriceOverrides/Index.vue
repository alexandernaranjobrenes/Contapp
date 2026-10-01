<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { EyeIcon } from '@lucide/vue';

const props = defineProps({
    overrides: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    users: { type: Array, default: () => [] },
});

const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const authorizedBy = ref(props.filters?.authorized_by ?? '');

function applyFilters() {
    router.get(route('price-overrides.index'), {
        from: from.value || undefined,
        to: to.value || undefined,
        authorized_by: authorizedBy.value === '' ? undefined : authorizedBy.value,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

const rows = computed(() => props.overrides.data ?? []);

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function customerName(o) {
    return o.sales_document?.business_partner?.name ?? o.sales_order?.business_partner?.name ?? '—';
}

function documentLabel(o) {
    if (o.sales_document) return o.sales_document.consecutive;
    if (o.sales_order) return `Pedido ${o.sales_order.number}`;
    return '—';
}

// Ficha del cambio (CLAUDE.md secc. 20): quién lo pidió, la lista, los dos
// precios y el motivo, y el enlace al documento.
const { selected, openDetail, closeDetail } = useRecordDetail(() => rows.value);
</script>

<template>
    <Head title="Cambios de precio autorizados" />

    <AppLayout title="Cambios de precio autorizados">
        <div class="view-toolbar">
            <form class="view-filters" @submit.prevent="applyFilters">
                <label class="filter-field">
                    <span>Desde</span>
                    <input v-model="from" type="date" @change="applyFilters">
                </label>
                <label class="filter-field">
                    <span>Hasta</span>
                    <input v-model="to" type="date" @change="applyFilters">
                </label>
                <label class="filter-field">
                    <span>Autorizante</span>
                    <select v-model="authorizedBy" @change="applyFilters">
                        <option value="">Todos los autorizantes</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </label>
            </form>
        </div>

        <p class="hint">
            Cada fila es una línea de factura que se apartó del precio de lista y que un administrador liberó.
            Un descuento aislado es una decisión comercial; el mismo descuento cien veces al mes es una lista
            de precios mal puesta.
        </p>

        <div class="card summary">
            <div>
                <strong>{{ summary.count }}</strong> cambio(s) en el período
            </div>
            <div :class="{ negative: Number(summary.total_difference) < 0 }">
                Diferencia acumulada: <strong>{{ money(summary.total_difference) }}</strong>
                <span class="muted small">
                    (negativo = se facturó por debajo de la lista)
                </span>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Documento</th>
                            <th>Artículo</th>
                            <th class="num">Diferencia</th>
                            <th>Lo autorizó</th>
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
                            <td class="code-cell">{{ o.created_at?.slice(0, 10) }}</td>
                            <td data-label="Documento" class="code-cell">{{ documentLabel(o) }}</td>
                            <td data-label="Artículo">
                                <strong class="code-cell">{{ o.item?.code ?? '—' }}</strong>
                                <span class="block muted small">{{ o.item?.name }}</span>
                            </td>
                            <td data-label="Diferencia" class="num" :class="Number(o.difference) < 0 ? 'negative' : 'positive'">
                                {{ money(o.difference) }}
                            </td>
                            <td data-label="Lo autorizó"><strong>{{ o.authorized_by?.name ?? '—' }}</strong></td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="muted empty-row">
                                No hubo cambios de precio autorizados en el período.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="overrides.last_page > 1" class="pagination">
            <Link
                v-for="link in overrides.links"
                :key="link.label"
                :href="link.url ?? ''"
                class="page-link"
                :class="{ active: link.active, disabled: !link.url }"
                preserve-scroll
                v-html="link.label"
            />
        </div>

        <DetailModal :open="!!selected" :title="selected ? `${selected.item?.code ?? ''} — ${documentLabel(selected)}` : ''" @close="closeDetail">
            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Fecha</dt>
                    <dd>{{ selected.created_at?.slice(0, 10) }}</dd>
                </div>
                <div>
                    <dt>Cliente</dt>
                    <dd>{{ customerName(selected) }}</dd>
                </div>
                <div>
                    <dt>Artículo</dt>
                    <dd>{{ selected.item?.code ?? '—' }} — {{ selected.item?.name }}</dd>
                </div>
                <div>
                    <dt>Lista de precios</dt>
                    <dd>{{ selected.price_list_code ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Precio de lista</dt>
                    <dd>{{ money(selected.list_unit_price) }}</dd>
                </div>
                <div>
                    <dt>Facturado</dt>
                    <dd>{{ money(selected.invoiced_unit_price) }}</dd>
                </div>
                <div>
                    <dt>Diferencia</dt>
                    <dd :class="Number(selected.difference) < 0 ? 'negative' : 'positive'">{{ money(selected.difference) }}</dd>
                </div>
                <div>
                    <dt>Lo pidió</dt>
                    <dd>{{ selected.requested_by?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Lo autorizó</dt>
                    <dd>{{ selected.authorized_by?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Motivo</dt>
                    <dd>{{ selected.reason ?? '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selected?.sales_document" :href="route('sales-documents.show', selected.sales_document.id)" class="btn btn-primary">
                    <EyeIcon /> Ver comprobante
                </Link>
                <Link v-else-if="selected?.sales_order" :href="route('sales-orders.show', selected.sales_order.id)" class="btn btn-primary">
                    <EyeIcon /> Ver pedido
                </Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.block { display: block; }
.summary { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1.1rem; margin-bottom: 0.75rem; font-size: 0.85rem; flex-wrap: wrap; }
.negative { color: var(--color-danger); }
.positive { color: var(--color-success); }
</style>
