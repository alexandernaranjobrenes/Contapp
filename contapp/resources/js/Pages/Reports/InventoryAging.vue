<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveReportButton from '../../Components/SaveReportButton.vue';
import { wrapDate } from '../../Utils/reportParameters';
import { formatMoney } from '../../Utils/money';
import { DownloadIcon, ScrollTextIcon } from '@lucide/vue';
import DetailModal from '../../Components/DetailModal.vue';

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

// Ficha de la fila (CLAUDE.md secc. 20): grupo, existencia y fecha desde la que
// no rota, y el enlace al kardex.
const selectedKey = ref(null);
const selectedRow = computed(() => props.result.rows.find((r) => `${r.item_id}-${r.warehouse_id}` === selectedKey.value) ?? null);
</script>

<template>
    <Head title="Antigüedad de inventario" />

    <AppLayout title="Antigüedad de inventario">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reports.inventory-aging.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.inventory-aging.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <SaveReportButton report-code="inventory-aging" :parameters="saveParameters" />
            </div>
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Al</span>
                    <input v-model="asOf" type="date">
                </label>
                <label class="filter-field" title="Cortes en días, separados por coma">
                    <span>Tramos (días)</span>
                    <input v-model="buckets" type="text" class="buckets-input" placeholder="30,60,90,180,360">
                </label>
                <label class="filter-field">
                    <span>Almacén</span>
                    <select v-model="warehouseId">
                        <option value="">Todos los almacenes</option>
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Grupo</span>
                    <select v-model="itemGroupId">
                        <option value="">Todos los grupos</option>
                        <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>
        </div>

        <p class="hint">
            La antigüedad se cuenta desde la <strong>última salida</strong>, no desde el último movimiento: un artículo
            al que se le sigue comprando pero que no se vende es justamente el caso grave, y medirlo por el último
            movimiento lo escondería. Lo que nunca ha salido se cuenta desde que entró y va marcado aparte.
            Es el insumo para decidir deterioro (NIC 2 §28); el reporte no lo calcula ni lo contabiliza.
        </p>

        <!-- Resumen por tramo: una rejilla y no una tabla, porque la cantidad
             de tramos la escoge el usuario y una fila de columnas crecería de
             lado (CLAUDE.md secc. 20). -->
        <div class="bucket-grid">
            <div v-for="(label, key) in result.bucket_labels" :key="key" class="card bucket-tile" :class="bucketClass(key)">
                <span class="bucket-label">{{ label }}</span>
                <strong class="bucket-value">{{ formatMoney(result.bucket_totals[key]) }}</strong>
            </div>
            <div class="card bucket-tile bucket-total">
                <span class="bucket-label">Total</span>
                <strong class="bucket-value">{{ formatMoney(result.total_value_local) }}</strong>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th class="num">Valor local</th>
                            <th class="num">Días</th>
                            <th>Tramo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in result.rows"
                            :key="`${row.item_id}-${row.warehouse_id}`"
                            class="clickable-row"
                            :class="bucketClass(row.bucket)"
                            tabindex="0"
                            @click="selectedKey = `${row.item_id}-${row.warehouse_id}`"
                            @keydown.enter="selectedKey = `${row.item_id}-${row.warehouse_id}`"
                            @keydown.space.prevent="selectedKey = `${row.item_id}-${row.warehouse_id}`"
                        >
                            <td>
                                <strong class="code">{{ row.item_code }}</strong> — {{ row.item_name }}
                                <span v-if="row.never_issued" class="never">nunca ha salido</span>
                            </td>
                            <td data-label="Almacén">{{ row.warehouse_code }}</td>
                            <td data-label="Valor local" class="num">{{ formatMoney(row.value_local) }}</td>
                            <td data-label="Días" class="num">{{ row.days_idle }}</td>
                            <td data-label="Tramo">{{ result.bucket_labels[row.bucket] }}</td>
                        </tr>
                        <tr v-if="result.rows.length === 0">
                            <td colspan="5" class="muted empty-row">Sin existencias al {{ result.as_of }}.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selectedRow" :title="selectedRow ? `${selectedRow.item_code} — ${selectedRow.item_name}` : ''" @close="selectedKey = null">
            <dl v-if="selectedRow" class="detail-list">
                <div>
                    <dt>Grupo</dt>
                    <dd>{{ selectedRow.item_group ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Almacén</dt>
                    <dd>{{ selectedRow.warehouse_code }}</dd>
                </div>
                <div>
                    <dt>Existencia</dt>
                    <dd>{{ quantity(selectedRow.quantity) }}</dd>
                </div>
                <div>
                    <dt>Valor local</dt>
                    <dd>{{ formatMoney(selectedRow.value_local) }}</dd>
                </div>
                <div>
                    <dt>Sin rotar desde</dt>
                    <dd>
                        {{ selectedRow.since_date }}
                        <span v-if="selectedRow.never_issued" class="never">nunca ha salido</span>
                    </dd>
                </div>
                <div>
                    <dt>Días</dt>
                    <dd>{{ selectedRow.days_idle }}</dd>
                </div>
                <div>
                    <dt>Tramo</dt>
                    <dd>{{ result.bucket_labels[selectedRow.bucket] }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selectedRow" :href="route('items.kardex', selectedRow.item_id)" class="btn btn-primary"><ScrollTextIcon /> Ver kardex</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.buckets-input { width: 10rem; }
.never { display: inline-block; margin-left: 0.4rem; font-size: 0.75rem; color: var(--color-warning); font-weight: 600; }

.bucket-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 9.5rem), 1fr));
    gap: 0.6rem;
    margin-bottom: 1rem;
}
.bucket-tile { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.7rem 0.9rem; margin: 0; }
.bucket-label { font-size: 0.75rem; color: var(--color-text-muted); }
.bucket-value { font-size: 0.95rem; font-variant-numeric: tabular-nums; }
.bucket-total { border-color: var(--color-primary); }

/* El tramo más viejo es el que hay que mirar primero. */
.bucket-tile.bucket-critical,
tr.bucket-critical td { background: var(--color-warning-soft); }

@media screen and (max-width: 1024px) {
    tr.bucket-critical td { background: none; }
    .table-responsive tbody tr.bucket-critical { background: var(--color-warning-soft); }
}
</style>
