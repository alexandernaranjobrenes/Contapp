<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
</script>

<template>
    <Head title="Lotes próximos a vencer" />

    <AppLayout title="Lotes próximos a vencer">
        <template #actions>
            <Link :href="route('items.index')" class="btn btn-ghost">Artículos</Link>
        </template>

        <p class="hint">
            Solo lotes <strong>con saldo</strong>: uno agotado ya no es un riesgo de obsolescencia aunque su fecha
            haya pasado. Este reporte no calcula ni contabiliza el deterioro — es el insumo para decidirlo
            (NIC 2 §28, menor entre costo y valor neto realizable).
        </p>

        <div class="card">
            <div class="card-header">
                <div class="filters">
                    <label>
                        Horizonte (días)
                        <input v-model.number="days" type="number" min="1" max="3650" class="input-sm">
                    </label>
                    <label>
                        Almacén
                        <select v-model="warehouseId" class="input-sm">
                            <option value="">Todos</option>
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
                        </select>
                    </label>
                </div>
                <span class="muted">{{ rows.length }} lote(s)</span>
            </div>

            <div class="summary-row">
                <span v-for="b in buckets" :key="b.key" class="badge" :class="bucketClass(b.key)">
                    {{ b.label }}: {{ summary[b.key] ?? 0 }}
                </span>
            </div>
        </div>

        <div v-for="b in buckets" :key="b.key">
            <template v-if="rowsOf(b.key).length">
                <h2 class="section-title">{{ b.label }}</h2>

                <div class="card">
                    <table>
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th>Lote</th>
                                <th>Vence</th>
                                <th class="right">Días</th>
                                <th class="right">Existencia</th>
                                <th>Almacenes</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rowsOf(b.key)" :key="r.id">
                                <td><strong class="num">{{ r.item_code }}</strong> — {{ r.item_name }}</td>
                                <td class="num code-cell">{{ r.code }}</td>
                                <td class="num">{{ r.expires_at }}</td>
                                <td class="num right">{{ r.days_left }}</td>
                                <td class="num right">{{ quantity(r.on_hand) }}</td>
                                <td class="muted small">{{ r.warehouses.join(', ') }}</td>
                                <td>
                                    <span class="badge" :class="r.status === 'active' ? 'badge-success' : 'badge-warning'">
                                        {{ r.status === 'active' ? 'Activo' : 'Retenido' }}
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <Link :href="route('item-lots.trace', [r.item_id, r.id])" class="btn btn-ghost">
                                        Trazabilidad
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <div v-if="!rows.length" class="card">
            <p class="muted empty-row">
                Ningún lote con saldo vence dentro de los próximos {{ days }} días.
            </p>
        </div>
    </AppLayout>
</template>

<style scoped>
.filters {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.filters label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.85rem;
}

.summary-row {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding: 0.75rem 0 0;
}
</style>
