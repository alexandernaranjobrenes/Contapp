<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

function applyFilters() {
    router.get(route('reorder.index'), {
        warehouse_id: warehouseId.value === '' ? undefined : warehouseId.value,
        item_group_id: itemGroupId.value === '' ? undefined : itemGroupId.value,
    }, { preserveState: true, replace: true, preserveScroll: true });
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
        <template #actions>
            <select v-model="warehouseId" class="search-input" @change="applyFilters">
                <option value="">Todos los almacenes</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
            </select>
            <select v-model="itemGroupId" class="search-input" @change="applyFilters">
                <option value="">Todos los grupos</option>
                <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
            </select>
            <Link :href="route('purchase-orders.index')" class="btn btn-ghost">Órdenes de compra</Link>
        </template>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p class="hint">
            Lo disponible para decidir una compra no es la existencia:
            <strong>disponible = existencia − apartado + en camino</strong>. Lo apartado por un pedido ya tiene
            dueño y no cubre demanda nueva; lo que viene en una orden abierta sí, y no contarlo haría pedir de
            nuevo algo que ya se pidió. Solo aparecen artículos con mínimo configurado.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ suggestions.length }} artículo(s) bajo mínimo</span>
                <span class="muted">Costo estimado total: <strong>{{ money(estimatedTotal) }}</strong></span>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th class="num">Existencia</th>
                            <th class="num">Apartado</th>
                            <th class="num">En camino</th>
                            <th class="num">Disponible</th>
                            <th class="num">Mínimo</th>
                            <th class="num">Máximo</th>
                            <th class="num">Sugerido</th>
                            <th class="num">Costo est.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in suggestions" :key="keyOf(s)" :class="severity(s)">
                            <td>
                                <input
                                    type="checkbox"
                                    :checked="!!selected[keyOf(s)]"
                                    :disabled="!s.is_purchase_item"
                                    :title="s.is_purchase_item ? '' : 'Este artículo no está marcado como comprable'"
                                    @change="toggle(s)"
                                >
                            </td>
                            <td>
                                <Link :href="route('reorder.levels', s.item_id)" class="link">{{ s.item_code }}</Link>
                                — {{ s.item_name }}
                            </td>
                            <td>{{ s.warehouse_code }}</td>
                            <td class="num">{{ quantity(s.on_hand) }}</td>
                            <td class="num muted">{{ quantity(s.reserved) }}</td>
                            <td class="num muted">{{ quantity(s.ordered) }}</td>
                            <td class="num"><strong>{{ quantity(s.available) }}</strong></td>
                            <td class="num muted">{{ quantity(s.minimum_stock) }}</td>
                            <td class="num muted">{{ s.maximum_stock === null ? '—' : quantity(s.maximum_stock) }}</td>
                            <td class="num">
                                <input
                                    v-if="selected[keyOf(s)]"
                                    v-model="quantities[keyOf(s)]"
                                    type="number" step="0.000001" min="0.000001" class="qty-input"
                                >
                                <span v-else>{{ quantity(s.suggested_quantity) }}</span>
                            </td>
                            <td class="num muted">{{ money(s.estimated_cost) }}</td>
                        </tr>
                        <tr v-if="!suggestions.length">
                            <td colspan="11" class="muted empty-row">
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

            <div class="grid-3">
                <div class="field">
                    <label>Proveedor</label>
                    <select v-model="orderForm.business_partner_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Fecha esperada (opcional)</label>
                    <input v-model="orderForm.expected_date" type="date">
                </div>

                <div class="field">
                    <label>Costo estimado</label>
                    <strong class="num">{{ money(chosenTotal) }}</strong>
                </div>
            </div>

            <p class="hint small">
                Las cantidades son editables: la sugerencia es un punto de partida, no una orden. El costo que se
                guarda es el promedio actual y es informativo — el real lo fija la recepción.
            </p>

            <button
                type="button"
                class="btn btn-primary"
                :disabled="orderForm.processing || orderForm.business_partner_id === ''"
                @click="createOrder"
            >
                Crear orden de compra
            </button>
        </div>
    </AppLayout>
</template>

<style scoped>
.num { text-align: right; }
.qty-input { width: 7rem; text-align: right; }
.table-scroll { overflow-x: auto; }
.order-panel { margin-top: 1rem; padding: 1rem 1.1rem; }
.order-panel h2 { font-size: 0.95rem; margin: 0 0 0.75rem; }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
/* Sin nada disponible es quiebre, no riesgo de quiebre. */
.critical { background: #fdecea; }
.warning { background: #fdf6e3; }
</style>
