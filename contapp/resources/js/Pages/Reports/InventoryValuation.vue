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
</script>

<template>
    <Head title="Existencias valorizadas" />

    <AppLayout title="Existencias valorizadas">
        <template #actions>
            <input v-model="asOf" type="date" class="date-input">
            <select v-model="warehouseId" class="date-input">
                <option value="">Todos los almacenes</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
            </select>
            <select v-model="itemGroupId" class="date-input">
                <option value="">Todos los grupos</option>
                <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
            </select>
            <label class="hide-zero">
                <input v-model="hideZero" type="checkbox">
                Ocultar existencias en cero
            </label>
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
            <a :href="exportUrl('reports.inventory-valuation.export')" class="btn btn-ghost">Exportar XLSX</a>
            <a :href="exportUrl('reports.inventory-valuation.export-pdf')" class="btn btn-ghost">Exportar PDF</a>
            <SaveReportButton report-code="inventory-valuation" :parameters="saveParameters" />
        </template>

        <p class="hint">
            Valor reconstruido de los movimientos del kardex hasta el corte, no del costo promedio de hoy: a una
            fecha pasada el promedio vigente era otro. Por construcción, el total tiene que coincidir con el saldo
            de las cuentas de inventario en esa misma fecha.
        </p>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th>Descripción</th>
                        <th>Grupo</th>
                        <th>Almacén</th>
                        <th>Unidad</th>
                        <th class="num">Existencia</th>
                        <th class="num">Costo unitario</th>
                        <th class="num">Valor local</th>
                        <th class="num">Valor USD</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in result.rows" :key="`${row.item_id}-${row.warehouse_id}`" :class="{ residual: isResidual(row) }">
                        <td>
                            <Link :href="route('items.kardex', row.item_id)" class="code-link">{{ row.item_code }}</Link>
                        </td>
                        <td>{{ row.item_name }}</td>
                        <td>{{ row.item_group ?? '—' }}</td>
                        <td>{{ row.warehouse_code }}</td>
                        <td>{{ row.uom ?? '—' }}</td>
                        <td class="num">{{ quantity(row.quantity) }}</td>
                        <td class="num">{{ formatMoney(row.unit_cost_local) }}</td>
                        <td class="num">{{ formatMoney(row.value_local) }}</td>
                        <td class="num">{{ formatMoney(row.value_foreign) }}</td>
                    </tr>
                    <tr v-if="result.rows.length === 0">
                        <td colspan="9" class="empty">Sin existencias al {{ result.as_of }}.</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" class="total-label">Total al {{ result.as_of }}</td>
                        <td class="num total-value">{{ formatMoney(result.total_value_local) }}</td>
                        <td class="num total-value">{{ formatMoney(result.total_value_foreign) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.date-input { margin-right: 0.4rem; }
.hide-zero { display: inline-flex; gap: 0.35rem; align-items: center; font-size: 0.85rem; margin-right: 0.4rem; }
.num { text-align: right; }
.empty { text-align: center; font-style: italic; color: #666; padding: 1rem; }
.total-label { text-align: right; font-weight: 600; }
.total-value { font-weight: 700; }
.code-link { font-variant-numeric: tabular-nums; }
/* Existencia en cero con valor residual: hay que investigarla. */
.residual { background: #fff6e5; }
</style>
