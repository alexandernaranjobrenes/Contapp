<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { RouteIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    buckets: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
});

const days = ref(props.filters.days ?? 90);
const warehouseId = ref(props.filters.warehouse_id ?? '');

function reload() {
    router.get(route('lot-expiry.index'), {
        days: days.value,
        warehouse_id: warehouseId.value === '' ? undefined : warehouseId.value,
    }, { preserveState: true, replace: true });
}

watch([days, warehouseId], reload);

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function bucketClass(key) {
    if (key === 'expired') return 'badge-danger';
    if (key === 'd15' || key === 'd30') return 'badge-warning';
    return 'badge-success';
}

function rowsOf(key) {
    return props.rows.filter((r) => r.bucket === key);
}

// Ficha del lote (CLAUDE.md secc. 20): dónde está y el enlace a su
// trazabilidad.
const selectedId = ref(null);
const selected = computed(() => props.rows.find((r) => r.id === selectedId.value) ?? null);
</script>

<template>
    <Head title="Lotes próximos a vencer" />

    <AppLayout title="Lotes próximos a vencer">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('items.index')" class="btn btn-ghost">Artículos</Link>
            </div>
            <div class="view-filters">
                <label class="filter-field">
                    <span>Horizonte (días)</span>
                    <input v-model.number="days" type="number" min="1" max="3650" class="days-input">
                </label>
                <label class="filter-field">
                    <span>Almacén</span>
                    <select v-model="warehouseId">
                        <option value="">Todos</option>
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
                    </select>
                </label>
            </div>
        </div>

        <p class="hint">
            Solo lotes <strong>con saldo</strong>: uno agotado ya no es un riesgo de obsolescencia aunque su fecha
            haya pasado. Este reporte no calcula ni contabiliza el deterioro — es el insumo para decidirlo
            (NIC 2 §28, menor entre costo y valor neto realizable).
        </p>

        <div class="card summary-card">
            <span class="muted">{{ rows.length }} lote(s)</span>
            <div class="summary-row">
                <span v-for="b in buckets" :key="b.key" class="badge" :class="bucketClass(b.key)">
                    {{ b.label }}: {{ summary[b.key] ?? 0 }}
                </span>
            </div>
        </div>

        <div v-for="b in buckets" :key="b.key">
            <template v-if="rowsOf(b.key).length">
                <h2 class="block-title">{{ b.label }}</h2>

                <div class="card">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Artículo</th>
                                    <th>Lote</th>
                                    <th>Vence</th>
                                    <th class="num">Existencia</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in rowsOf(b.key)"
                                    :key="r.id"
                                    class="clickable-row"
                                    tabindex="0"
                                    @click="selectedId = r.id"
                                    @keydown.enter="selectedId = r.id"
                                    @keydown.space.prevent="selectedId = r.id"
                                >
                                    <td><strong class="code">{{ r.item_code }}</strong> — {{ r.item_name }}</td>
                                    <td data-label="Lote" class="code">{{ r.code }}</td>
                                    <td data-label="Vence" class="code">
                                        {{ r.expires_at }}
                                        <span class="muted days">({{ r.days_left }} días)</span>
                                    </td>
                                    <td data-label="Existencia" class="num">{{ quantity(r.on_hand) }}</td>
                                    <td data-label="Estado">
                                        <span class="badge" :class="r.status === 'active' ? 'badge-success' : 'badge-warning'">
                                            {{ r.status === 'active' ? 'Activo' : 'Retenido' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>

        <div v-if="!rows.length" class="card">
            <p class="muted empty-row">
                Ningún lote con saldo vence dentro de los próximos {{ days }} días.
            </p>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `Lote ${selected.code}` : ''" @close="selectedId = null">
            <template #badge>
                <span v-if="selected" class="badge" :class="bucketClass(selected.bucket)">{{ buckets.find((b) => b.key === selected.bucket)?.label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div class="full">
                    <dt>Artículo</dt>
                    <dd>{{ selected.item_code }} — {{ selected.item_name }}</dd>
                </div>
                <div>
                    <dt>Vence</dt>
                    <dd>{{ selected.expires_at }} ({{ selected.days_left }} días)</dd>
                </div>
                <div>
                    <dt>Existencia</dt>
                    <dd>{{ quantity(selected.on_hand) }}</dd>
                </div>
                <div>
                    <dt>Almacenes</dt>
                    <dd>{{ selected.warehouses.join(', ') || '—' }}</dd>
                </div>
                <div>
                    <dt>Estado</dt>
                    <dd>{{ selected.status === 'active' ? 'Activo' : 'Retenido' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selected" :href="route('item-lots.trace', [selected.item_id, selected.id])" class="btn btn-primary">
                    <RouteIcon /> Trazabilidad
                </Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.days { font-size: 0.76rem; }
.days-input { width: 7rem; }
.summary-card { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem 1rem; padding: 0.75rem 1.1rem; }
.summary-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
</style>
