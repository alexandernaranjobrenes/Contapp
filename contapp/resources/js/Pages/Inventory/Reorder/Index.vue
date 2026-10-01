<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { DownloadIcon, ShoppingCartIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    suggestions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
    itemGroups: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    estimatedTotal: { type: String, default: '0.00' },
});

const page = usePage();

const warehouseId = ref(props.filters?.warehouse_id ?? '');
const itemGroupId = ref(props.filters?.item_group_id ?? '');

function queryParams() {
    return {
        warehouse_id: warehouseId.value === '' ? undefined : warehouseId.value,
        item_group_id: itemGroupId.value === '' ? undefined : itemGroupId.value,
    };
}

function applyFilters() {
    router.get(route('reorder.index'), queryParams(), { preserveState: true, replace: true, preserveScroll: true });
}

// La exportación arrastra los mismos filtros que la pantalla: un archivo que
// no coincide con lo que se está viendo es peor que no tenerlo.
function exportUrl(routeName) {
    return route(routeName, queryParams());
}

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function keyOf(s) {
    return `${s.item_id}-${s.warehouse_id}`;
}

// Selección y cantidad editable: la sugerencia es un punto de partida, no
// una orden. Quien compra ajusta según empaque, descuento por volumen o lo
// que le diga el proveedor.
const selected = ref({});
const quantities = ref({});

function toggle(s) {
    const key = keyOf(s);

    if (selected.value[key]) {
        delete selected.value[key];
        return;
    }

    // Solo lo comprable se puede ordenar: un artículo marcado como no
    // comprable igual puede estar bajo mínimo (se fabrica), pero no va en
    // una orden de compra.
    if (! s.is_purchase_item) return;

    selected.value[key] = true;
    if (quantities.value[key] === undefined) quantities.value[key] = s.suggested_quantity;
}

const chosen = computed(() => props.suggestions.filter((s) => selected.value[keyOf(s)]));

const chosenTotal = computed(() => chosen.value.reduce(
    (sum, s) => sum + Number(quantities.value[keyOf(s)] ?? 0) * Number(s.avg_cost_local), 0
));

const orderForm = useForm({ business_partner_id: '', expected_date: '', lines: [] });

function createOrder() {
    orderForm.transform(() => ({
        business_partner_id: orderForm.business_partner_id,
        expected_date: orderForm.expected_date === '' ? null : orderForm.expected_date,
        lines: chosen.value.map((s) => ({
            item_id: s.item_id,
            warehouse_id: s.warehouse_id,
            quantity: quantities.value[keyOf(s)],
        })),
    })).post(route('reorder.order'));
}

// Lo más descubierto primero ya viene ordenado del servidor; esto solo
// colorea qué tan grave es.
function severity(s) {
    if (Number(s.available) <= 0) return 'critical';
    if (s.coverage <= 0.5) return 'warning';
    return '';
}
</script>

<template>
    <Head title="Sugerencia de compra" />

    <AppLayout title="Sugerencia de compra">
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('reorder.export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('reorder.export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <Link :href="route('purchase-orders.index')" class="btn btn-ghost">Órdenes de compra</Link>
            </div>
            <div class="view-filters">
                <select v-model="warehouseId" aria-label="Almacén" @change="applyFilters">
                    <option value="">Todos los almacenes</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
                </select>
                <select v-model="itemGroupId" aria-label="Grupo" @change="applyFilters">
                    <option value="">Todos los grupos</option>
                    <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                </select>
            </div>
        </div>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p class="hint">
            Lo disponible para decidir una compra no es la existencia:
            <strong>disponible = existencia − apartado + en camino</strong>. Lo apartado por un pedido ya tiene
            dueño y no cubre demanda nueva; lo que viene en una orden abierta sí, y no contarlo haría pedir de
            nuevo algo que ya se pidió. Solo aparecen artículos con mínimo configurado.
        </p>

        <div class="list-summary">
            <span class="muted">{{ suggestions.length }} artículo(s) bajo mínimo</span>
            <span class="muted">Costo estimado total: <strong>{{ money(estimatedTotal) }}</strong></span>
        </div>

        <!-- Grilla de selección: la casilla elige qué va a la orden y la
             cantidad se ajusta en la fila (CLAUDE.md secc. 20, excepción).
             Lo apartado y lo que viene en camino se leen debajo del
             disponible, que es lo que suman. -->
        <div class="card">
            <div class="table-responsive capture-grid">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th class="num">Disponible</th>
                            <th class="num">Mín. / máx.</th>
                            <th class="num">Sugerido</th>
                            <th class="num">Costo est.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in suggestions" :key="keyOf(s)" :class="severity(s)">
                            <td>
                                <label class="pick-cell">
                                    <input
                                        type="checkbox"
                                        :checked="!!selected[keyOf(s)]"
                                        :disabled="!s.is_purchase_item"
                                        :title="s.is_purchase_item ? '' : 'Este artículo no está marcado como comprable'"
                                        @change="toggle(s)"
                                    >
                                    <span>
                                        <Link :href="route('reorder.levels', s.item_id)" class="link">{{ s.item_code }}</Link>
                                        — {{ s.item_name }}
                                    </span>
                                </label>
                            </td>
                            <td data-label="Almacén">{{ s.warehouse_code }}</td>
                            <td data-label="Disponible" class="num">
                                <strong>{{ quantity(s.available) }}</strong>
                                <span class="breakdown">existencia {{ quantity(s.on_hand) }} · apartado {{ quantity(s.reserved) }} · en camino {{ quantity(s.ordered) }}</span>
                            </td>
                            <td data-label="Mín. / máx." class="num muted">
                                {{ quantity(s.minimum_stock) }} / {{ s.maximum_stock === null ? '—' : quantity(s.maximum_stock) }}
                            </td>
                            <td data-label="Sugerido" class="num">
                                <input
                                    v-if="selected[keyOf(s)]"
                                    v-model="quantities[keyOf(s)]"
                                    type="number" step="0.000001" min="0.000001" class="qty-input"
                                    :aria-label="`Cantidad a pedir de ${s.item_code}`"
                                >
                                <span v-else>{{ quantity(s.suggested_quantity) }}</span>
                            </td>
                            <td data-label="Costo est." class="num muted">{{ money(s.estimated_cost) }}</td>
                        </tr>
                        <tr v-if="!suggestions.length">
                            <td colspan="6" class="muted empty-row">
                                Nada bajo mínimo. Si esperabas ver artículos acá, revisá que tengan mínimo
                                configurado: sin mínimo no hay control de reorden.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="chosen.length" class="card order-panel">
            <h2>Crear orden de compra con {{ chosen.length }} línea(s)</h2>

            <div class="form-grid">
                <div class="field">
                    <label for="reorder-supplier">Proveedor</label>
                    <select id="reorder-supplier" v-model="orderForm.business_partner_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="reorder-expected">Fecha esperada (opcional)</label>
                    <input id="reorder-expected" v-model="orderForm.expected_date" type="date">
                </div>

                <div class="field">
                    <span class="field-label">Costo estimado</span>
                    <strong class="estimated">{{ money(chosenTotal) }}</strong>
                </div>
            </div>

            <p class="hint small">
                Las cantidades son editables: la sugerencia es un punto de partida, no una orden. El costo que se
                guarda es el promedio actual y es informativo — el real lo fija la recepción.
            </p>

            <div class="form-actions">
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="orderForm.processing || orderForm.business_partner_id === ''"
                    @click="createOrder"
                >
                    <ShoppingCartIcon /> Crear orden de compra
                </button>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.list-summary { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.25rem 1rem; margin-bottom: 0.6rem; font-size: 0.85rem; }
.pick-cell { display: inline-flex; align-items: flex-start; gap: 0.5rem; }
.pick-cell input { margin-top: 0.2rem; }
.breakdown { display: block; font-size: 0.7rem; color: var(--color-text-muted); font-weight: 400; white-space: normal; }
.qty-input { width: 7rem; text-align: right; }
.order-panel { margin-top: 1rem; padding: 1rem 1.1rem; }
.order-panel h2 { font-size: 0.95rem; margin: 0 0 0.75rem; }
.field-label { font-size: 0.78rem; font-weight: 600; color: var(--color-text-muted); }
.estimated { font-variant-numeric: tabular-nums; min-height: 2.25rem; display: flex; align-items: center; }
/* Sin nada disponible es quiebre, no riesgo de quiebre. */
.critical td { background: var(--color-danger-soft); }
.warning td { background: var(--color-warning-soft); }

@media screen and (max-width: 1024px) {
    .critical td, .warning td { background: none; }
    .table-responsive tbody tr.critical { background: var(--color-danger-soft); }
    .table-responsive tbody tr.warning { background: var(--color-warning-soft); }
    .qty-input { width: auto; }
}
</style>
