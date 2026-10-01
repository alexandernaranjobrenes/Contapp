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
const warehouseId = ref(props.filters.warehouse_id ?? '');
const itemGroupId = ref(props.filters.item_group_id ?? '');
const hideZero = ref(props.filters.hide_zero);

const saveParameters = computed(() => {
    const params = { as_of: wrapDate(asOf.value), hide_zero: hideZero.value };
    if (warehouseId.value !== '') params.warehouse_id = warehouseId.value;
    if (itemGroupId.value !== '') params.item_group_id = itemGroupId.value;
    return params;
});

function queryParams() {
    return {
        as_of: asOf.value || undefined,
        warehouse_id: warehouseId.value === '' ? undefined : warehouseId.value,
        item_group_id: itemGroupId.value === '' ? undefined : itemGroupId.value,
        hide_zero: hideZero.value ? 1 : 0,
    };
}

function applyFilter() {
    router.get(route('reports.inventory-valuation.index'), queryParams(), { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, queryParams());
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

// Una fila con existencia en cero pero valor distinto de cero es un síntoma,
// no un dato: el promedio dejó residuo en una cuenta que ya no tiene
// mercancía que lo respalde. Se marca para que salte a la vista.
function isResidual(row) {
    return Number(row.quantity) === 0 && Number(row.value_local) !== 0;
}

// Ficha de la fila (CLAUDE.md secc. 20): grupo, unidad y valor en dólares, y
// el enlace al kardex del artículo.
const selectedKey = ref(null);
const selectedRow = computed(() => props.result.rows.find((r) => `${r.item_id}-${r.warehouse_id}` === selectedKey.value) ?? null);
</script>

<template>
    <Head title="Existencias valorizadas" />

    <AppLayout title="Existencias valorizadas">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reports.inventory-valuation.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reports.inventory-valuation.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <SaveReportButton report-code="inventory-valuation" :parameters="saveParameters" />
            </div>
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Al</span>
                    <input v-model="asOf" type="date">
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
                <label class="check">
                    <input v-model="hideZero" type="checkbox">
                    Ocultar existencias en cero
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>
        </div>

        <p class="hint">
            Valor reconstruido de los movimientos del kardex hasta el corte, no del costo promedio de hoy: a una
            fecha pasada el promedio vigente era otro. Por construcción, el total tiene que coincidir con el saldo
            de las cuentas de inventario en esa misma fecha.
        </p>

        <div class="card">
            <div class="table-responsive table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th class="num">Existencia</th>
                            <th class="num">Costo unitario</th>
                            <th class="num">Valor local</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in result.rows"
                            :key="`${row.item_id}-${row.warehouse_id}`"
                            class="clickable-row"
                            :class="{ residual: isResidual(row) }"
                            tabindex="0"
                            @click="selectedKey = `${row.item_id}-${row.warehouse_id}`"
                            @keydown.enter="selectedKey = `${row.item_id}-${row.warehouse_id}`"
                            @keydown.space.prevent="selectedKey = `${row.item_id}-${row.warehouse_id}`"
                        >
                            <td><strong class="code">{{ row.item_code }}</strong> — {{ row.item_name }}</td>
                            <td data-label="Almacén">{{ row.warehouse_code }}</td>
                            <td data-label="Existencia" class="num">{{ quantity(row.quantity) }}</td>
                            <td data-label="Costo unitario" class="num">{{ formatMoney(row.unit_cost_local) }}</td>
                            <td data-label="Valor local" class="num">{{ formatMoney(row.value_local) }}</td>
                        </tr>
                        <tr v-if="result.rows.length === 0">
                            <td colspan="5" class="muted empty-row">Sin existencias al {{ result.as_of }}.</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="total-label">Total al {{ result.as_of }}</td>
                            <td data-label="Valor local" class="num total-value">{{ formatMoney(result.total_value_local) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="hint total-usd">Total en USD al {{ result.as_of }}: <strong>{{ formatMoney(result.total_value_foreign) }}</strong></p>

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
                    <dd>{{ quantity(selectedRow.quantity) }} {{ selectedRow.uom ?? '' }}</dd>
                </div>
                <div>
                    <dt>Costo unitario</dt>
                    <dd>{{ formatMoney(selectedRow.unit_cost_local) }}</dd>
                </div>
                <div>
                    <dt>Valor local</dt>
                    <dd>{{ formatMoney(selectedRow.value_local) }}</dd>
                </div>
                <div>
                    <dt>Valor USD</dt>
                    <dd>{{ formatMoney(selectedRow.value_foreign) }}</dd>
                </div>
            </dl>
            <p v-if="selectedRow && isResidual(selectedRow)" class="flash flash-warning residual-note">
                Existencia en cero con valor: el promedio dejó un residuo sin mercancía que lo respalde. Hay que investigarlo.
            </p>

            <template #actions>
                <Link v-if="selectedRow" :href="route('items.kardex', selectedRow.item_id)" class="btn btn-primary"><ScrollTextIcon /> Ver kardex</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.total-label { text-align: right; font-weight: 600; }
.total-value { font-weight: 700; }
.total-usd { margin: 0.6rem 0 0; text-align: right; }
.residual-note { margin: 0.9rem 0 0; }
/* Existencia en cero con valor residual: hay que investigarla. */
.residual td { background: var(--color-warning-soft); }

@media screen and (max-width: 1024px) {
    .residual td { background: none; }
    .table-responsive tbody tr.residual { background: var(--color-warning-soft); }
    .total-label { text-align: left; }
    .total-usd { text-align: left; }
}
</style>
