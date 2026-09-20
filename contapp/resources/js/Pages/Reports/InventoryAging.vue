<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    filters: { type: Object, required: true },
    result: { type: Object, required: true },
    warehouses: { type: Array, default: () => [] },
    itemGroups: { type: Array, default: () => [] },
});

const asOf = ref(props.filters.as_of);
const buckets = ref(props.filters.buckets ?? props.result.buckets_input);
const warehouseId = ref(props.filters.warehouse_id ?? '');
const itemGroupId = ref(props.filters.item_group_id ?? '');

const saveParameters = computed(() => {
    const params = { as_of: wrapDate(asOf.value) };
    if (buckets.value) params.buckets = buckets.value;
    if (warehouseId.value !== '') params.warehouse_id = warehouseId.value;
    if (itemGroupId.value !== '') params.item_group_id = itemGroupId.value;
    return params;
});

function queryParams() {
    return {
        as_of: asOf.value || undefined,
        buckets: buckets.value || undefined,
        warehouse_id: warehouseId.value === '' ? undefined : warehouseId.value,
        item_group_id: itemGroupId.value === '' ? undefined : itemGroupId.value,
    };
}

function applyFilter() {
    router.get(route('reports.inventory-aging.index'), queryParams(), { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, queryParams());
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const bucketKeys = computed(() => Object.keys(props.result.bucket_labels));

// El último tramo es el que más pesa para una decisión de deterioro.
const lastBucketKey = computed(() => bucketKeys.value[bucketKeys.value.length - 1]);

function bucketClass(key) {
    return key === lastBucketKey.value ? 'bucket-critical' : '';
}
</script>

<template>
    <Head title="Antigüedad de inventario" />

    <AppLayout title="Antigüedad de inventario">
        <template #actions>
            <input v-model="asOf" type="date" class="date-input">
            <input v-model="buckets" type="text" class="date-input buckets-input" placeholder="30,60,90,180,360" title="Cortes en días">
            <select v-model="warehouseId" class="date-input">
                <option value="">Todos los almacenes</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
            </select>
            <select v-model="itemGroupId" class="date-input">
                <option value="">Todos los grupos</option>
                <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
            </select>
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.inventory-aging.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.inventory-aging.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="inventory-aging" :parameters="saveParameters" />
        </template>

        <p class="hint">
            La antigüedad se cuenta desde la <strong>última salida</strong>, no desde el último movimiento: un artículo
            al que se le sigue comprando pero que no se vende es justamente el caso grave, y medirlo por el último
            movimiento lo escondería. Lo que nunca ha salido se cuenta desde que entró y va marcado aparte.
            Es el insumo para decidir deterioro (NIC 2 §28); el reporte no lo calcula ni lo contabiliza.
        </p>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th v-for="(label, key) in result.bucket_labels" :key="key" class="num" :class="bucketClass(key)">
                            {{ label }}
                        </th>
                        <th class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td v-for="key in bucketKeys" :key="key" class="num" :class="bucketClass(key)">
                            {{ formatMoney(result.bucket_totals[key]) }}
                        </td>
                        <td class="num total-value">{{ formatMoney(result.total_value_local) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th>Descripción</th>
                        <th>Grupo</th>
                        <th>Almacén</th>
                        <th class="num">Existencia</th>
                        <th class="num">Valor local</th>
                        <th>Sin rotar desde</th>
                        <th class="num">Días</th>
                        <th>Tramo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in result.rows"
                        :key="`${row.item_id}-${row.warehouse_id}`"
                        :class="bucketClass(row.bucket)"
                    >
                        <td>
                            <Link :href="route('items.kardex', row.item_id)" class="code-link">{{ row.item_code }}</Link>
                        </td>
                        <td>{{ row.item_name }}</td>
                        <td>{{ row.item_group ?? '—' }}</td>
                        <td>{{ row.warehouse_code }}</td>
                        <td class="num">{{ quantity(row.quantity) }}</td>
                        <td class="num">{{ formatMoney(row.value_local) }}</td>
                        <td>
                            {{ row.since_date }}
                            <span v-if="row.never_issued" class="never">nunca ha salido</span>
                        </td>
                        <td class="num">{{ row.days_idle }}</td>
                        <td>{{ result.bucket_labels[row.bucket] }}</td>
                    </tr>
                    <tr v-if="result.rows.length === 0">
                        <td colspan="9" class="empty">Sin existencias al {{ result.as_of }}.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.date-input { margin-right: 0.4rem; }
.buckets-input { width: 9rem; }
.num { text-align: right; }
.empty { text-align: center; font-style: italic; color: #666; padding: 1rem; }
.total-value { font-weight: 700; }
.code-link { font-variant-numeric: tabular-nums; }
.never { display: inline-block; margin-left: 0.4rem; font-size: 0.75rem; color: #a04000; font-weight: 600; }
/* El tramo más viejo es el que hay que mirar primero. */
.bucket-critical { background: #fdf0ea; }
</style>
