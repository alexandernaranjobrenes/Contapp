<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
</script>

<template>
    <Head title="Cambios de precio autorizados" />

    <AppLayout title="Cambios de precio autorizados">
        <template #actions>
            <input v-model="from" type="date" class="search-input" @change="applyFilters">
            <input v-model="to" type="date" class="search-input" @change="applyFilters">
            <select v-model="authorizedBy" class="search-input" @change="applyFilters">
                <option value="">Todos los autorizantes</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
        </template>

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
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Documento</th>
                            <th>Cliente</th>
                            <th>Artículo</th>
                            <th>Lista</th>
                            <th class="right">Precio de lista</th>
                            <th class="right">Facturado</th>
                            <th class="right">Diferencia</th>
                            <th>Lo pidió</th>
                            <th>Lo autorizó</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in rows" :key="o.id">
                            <td class="muted small">{{ o.created_at?.slice(0, 10) }}</td>
                            <td>
                                <Link
                                    v-if="o.sales_document"
                                    :href="route('sales-documents.show', o.sales_document.id)"
                                    class="num"
                                >{{ o.sales_document.consecutive }}</Link>
                                <template v-else-if="o.sales_order">
                                    <Link :href="route('sales-orders.show', o.sales_order.id)" class="num">
                                        {{ o.sales_order.number }}
                                    </Link>
                                    <span class="block muted small">pedido</span>
                                </template>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>
                                {{ o.sales_document?.business_partner?.name
                                    ?? o.sales_order?.business_partner?.name ?? '—' }}
                            </td>
                            <td>
                                <strong class="num">{{ o.item?.code ?? '—' }}</strong>
                                <span class="block muted small">{{ o.item?.name }}</span>
                            </td>
                            <td class="muted small">{{ o.price_list_code ?? '—' }}</td>
                            <td class="right">{{ money(o.list_unit_price) }}</td>
                            <td class="right">{{ money(o.invoiced_unit_price) }}</td>
                            <td class="right" :class="Number(o.difference) < 0 ? 'negative' : 'positive'">
                                {{ money(o.difference) }}
                            </td>
                            <td class="muted small">{{ o.requested_by?.name ?? '—' }}</td>
                            <td class="small"><strong>{{ o.authorized_by?.name ?? '—' }}</strong></td>
                            <td class="muted small">{{ o.reason ?? '—' }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="11" class="muted empty-row">
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
    </AppLayout>
</template>

<style scoped>
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.small { font-size: 0.76rem; }
.block { display: block; }
.hint { color: var(--color-text-muted); font-size: 0.82rem; margin: 0 0 0.75rem; max-width: 80ch; }
.summary { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1.1rem; margin-bottom: 0.75rem; font-size: 0.85rem; flex-wrap: wrap; }
.negative { color: var(--color-danger); }
.positive { color: var(--color-success, #1a7f4b); }
.empty-row { text-align: center; padding: 1.5rem; }
.pagination { display: flex; gap: 0.25rem; margin-top: 0.75rem; flex-wrap: wrap; }
.page-link { padding: 0.25rem 0.55rem; border-radius: var(--radius-sm); font-size: 0.8rem; }
.page-link.active { background: var(--color-primary, #0B1F3A); color: #fff; }
.page-link.disabled { opacity: 0.4; pointer-events: none; }
</style>
