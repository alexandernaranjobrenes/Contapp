<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    item: { type: Object, required: true },
    uomCode: { type: String, default: null },
    warehouses: { type: Array, default: () => [] },
    selectedWarehouseId: { type: Number, default: null },
    movements: { type: Array, default: () => [] },
    from: { type: String, default: null },
    to: { type: String, default: null },
    opening: { type: Object, default: null },
});

const warehouseFilter = ref(props.selectedWarehouseId ?? '');
const from = ref(props.from ?? '');
const to = ref(props.to ?? '');

function applyFilter() {
    router.get(
        route('items.kardex', props.item.id),
        {
            warehouse_id: warehouseFilter.value === '' ? undefined : warehouseFilter.value,
            from: from.value === '' ? undefined : from.value,
            to: to.value === '' ? undefined : to.value,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

// Saldo acumulado del ARTÍCULO a lo largo del listado. Cuando se filtra por
// un almacén coincide con balance_quantity del kardex; sin filtro no, porque
// esa columna es el saldo de su propio almacén, no el consolidado.
const rows = computed(() => {
    // Arranca en el saldo inicial, no en cero: con un rango de fechas, los
    // movimientos anteriores están resumidos en esa línea y el saldo
    // corriente tiene que continuarla.
    let running = Number(props.opening?.quantity ?? 0);

    return props.movements.map((m) => {
        running += (m.direction === 'in' ? 1 : -1) * Number(m.quantity);
        return { ...m, running };
    });
});

const totalIn = computed(() => props.movements
    .filter((m) => m.direction === 'in')
    .reduce((sum, m) => sum + Number(m.total_cost_local), 0));

const totalOut = computed(() => props.movements
    .filter((m) => m.direction === 'out')
    .reduce((sum, m) => sum + Number(m.total_cost_local), 0));
</script>

<template>
    <Head :title="`Kardex ${item.code}`" />

    <AppLayout :title="`Kardex — ${item.code} ${item.name}`">
        <template #actions>
            <select v-model="warehouseFilter" class="filter-select" @change="applyFilter">
                <option value="">Todos los almacenes</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
            </select>
            <input v-model="from" type="date" class="filter-select" title="Desde">
            <input v-model="to" type="date" class="filter-select" title="Hasta">
            <button type="button" class="btn btn-primary" @click="applyFilter">Consultar</button>
        </template>

        <div class="card summary">
            <div>
                <span class="muted small">Costo promedio (LC)</span>
                <strong class="num">{{ money(item.avg_cost_local) }}</strong>
            </div>
            <div>
                <span class="muted small">Costo promedio (FC)</span>
                <strong class="num">{{ money(item.avg_cost_foreign) }}</strong>
            </div>
            <div>
                <span class="muted small">Unidad</span>
                <strong>{{ uomCode ?? '—' }}</strong>
            </div>
            <div>
                <span class="muted small">Entradas valuadas</span>
                <strong class="num">{{ money(totalIn) }}</strong>
            </div>
            <div>
                <span class="muted small">Salidas valuadas</span>
                <strong class="num">{{ money(totalOut) }}</strong>
            </div>
        </div>

        <div class="card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Almacén</th>
                            <th>Mov.</th>
                            <th class="right">Cantidad</th>
                            <th class="right">Costo unitario</th>
                            <th class="right">Total (LC)</th>
                            <th class="right">Total (FC)</th>
                            <th class="right">Saldo almacén</th>
                            <th class="right">Saldo artículo</th>
                            <th class="right">Promedio tras el mov.</th>
                            <th>Asiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="opening" class="opening-row">
                            <td class="num">{{ from }}</td>
                            <td colspan="2"><strong>Saldo inicial</strong></td>
                            <td class="num right">—</td>
                            <td class="num right">{{ money(opening.unit_cost_local) }}</td>
                            <td class="num right">{{ money(opening.value_local) }}</td>
                            <td class="num right">—</td>
                            <td class="num right">—</td>
                            <td class="num right"><strong>{{ quantity(opening.quantity) }}</strong></td>
                            <td class="num right">—</td>
                            <td>—</td>
                        </tr>
                        <tr v-for="m in rows" :key="m.id">
                            <td class="num">{{ m.posting_date }}</td>
                            <td class="code-cell">{{ m.warehouse_code }}</td>
                            <td>
                                <span class="badge" :class="m.direction === 'in' ? 'badge-success' : 'badge-warning'">
                                    {{ m.direction === 'in' ? 'Entra' : 'Sale' }}
                                </span>
                            </td>
                            <td class="num right">{{ quantity(m.quantity) }}</td>
                            <td class="num right">{{ money(m.unit_cost_local) }}</td>
                            <td class="num right">{{ money(m.total_cost_local) }}</td>
                            <td class="num right">{{ money(m.total_cost_foreign) }}</td>
                            <td class="num right muted">{{ quantity(m.balance_quantity) }}</td>
                            <td class="num right"><strong>{{ quantity(m.running) }}</strong></td>
                            <td class="num right muted">{{ money(m.avg_cost_local_after) }}</td>
                            <td>
                                <Link
                                    v-if="m.inventory_document_id"
                                    :href="route('inventory-movements.show', m.inventory_document_id)"
                                    class="link"
                                >
                                    #{{ m.journal_document_number }}
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="11" class="muted empty-row">
                                Este artículo todavía no tiene movimientos registrados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="hint">
            El kardex es un registro <strong>append-only</strong>: ninguna fila se edita ni se borra. Una corrección se
            registra como un movimiento nuevo, igual que un asiento contabilizado se anula con su reversión.
        </p>
    </AppLayout>
</template>

<style scoped>
.opening-row { background: #f5f7fa; }
.filter-select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
}

.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 1.75rem;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
}

.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0.75rem 0 0; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }
</style>
