<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeftIcon, EyeIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';

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

// Ficha de un movimiento (CLAUDE.md secc. 20): el resto de las cifras y el
// enlace al documento que lo generó.
const selectedId = ref(null);
const selected = computed(() => rows.value.find((m) => m.id === selectedId.value) ?? null);
</script>

<template>
    <Head :title="`Kardex ${item.code}`" />

    <AppLayout :title="`Kardex — ${item.code} ${item.name}`">
        <div class="view-toolbar">
            <Link :href="route('items.index')" class="btn btn-ghost"><ArrowLeftIcon /> Artículos</Link>
            <form class="view-filters" @submit.prevent="applyFilter">
                <label class="filter-field">
                    <span>Almacén</span>
                    <select v-model="warehouseFilter">
                        <option value="">Todos los almacenes</option>
                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Desde</span>
                    <input v-model="from" type="date">
                </label>
                <label class="filter-field">
                    <span>Hasta</span>
                    <input v-model="to" type="date">
                </label>
                <button type="submit" class="btn btn-primary">Consultar</button>
            </form>
        </div>

        <div class="card summary">
            <div>
                <span class="muted small">Costo promedio (LC)</span>
                <strong class="num-value">{{ money(item.avg_cost_local) }}</strong>
            </div>
            <div>
                <span class="muted small">Costo promedio (FC)</span>
                <strong class="num-value">{{ money(item.avg_cost_foreign) }}</strong>
            </div>
            <div>
                <span class="muted small">Unidad</span>
                <strong>{{ uomCode ?? '—' }}</strong>
            </div>
            <div>
                <span class="muted small">Entradas valuadas</span>
                <strong class="num-value">{{ money(totalIn) }}</strong>
            </div>
            <div>
                <span class="muted small">Salidas valuadas</span>
                <strong class="num-value">{{ money(totalOut) }}</strong>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Almacén</th>
                            <th>Mov.</th>
                            <th class="num">Cantidad</th>
                            <th class="num">Total (LC)</th>
                            <th class="num">Saldo artículo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="opening" class="opening-row">
                            <td class="date-cell">{{ from }}</td>
                            <td colspan="2"><strong>Saldo inicial</strong></td>
                            <td data-label="Cantidad" class="num">—</td>
                            <td data-label="Valor (LC)" class="num">{{ money(opening.value_local) }}</td>
                            <td data-label="Saldo artículo" class="num"><strong>{{ quantity(opening.quantity) }}</strong></td>
                        </tr>
                        <tr
                            v-for="m in rows"
                            :key="m.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="selectedId = m.id"
                            @keydown.enter="selectedId = m.id"
                            @keydown.space.prevent="selectedId = m.id"
                        >
                            <td class="date-cell">{{ m.posting_date }}</td>
                            <td data-label="Almacén" class="code-cell">{{ m.warehouse_code }}</td>
                            <td data-label="Mov.">
                                <span class="badge" :class="m.direction === 'in' ? 'badge-success' : 'badge-warning'">
                                    {{ m.direction === 'in' ? 'Entra' : 'Sale' }}
                                </span>
                            </td>
                            <td data-label="Cantidad" class="num">{{ quantity(m.quantity) }}</td>
                            <td data-label="Total (LC)" class="num">{{ money(m.total_cost_local) }}</td>
                            <td data-label="Saldo artículo" class="num"><strong>{{ quantity(m.running) }}</strong></td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="6" class="muted empty-row">
                                Este artículo todavía no tiene movimientos registrados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="hint kardex-hint">
            El kardex es un registro <strong>append-only</strong>: ninguna fila se edita ni se borra. Una corrección se
            registra como un movimiento nuevo, igual que un asiento contabilizado se anula con su reversión.
        </p>

        <DetailModal
            :open="!!selected"
            :title="selected ? `${selected.direction === 'in' ? 'Entrada' : 'Salida'} del ${selected.posting_date}` : ''"
            @close="selectedId = null"
        >
            <template #badge>
                <span v-if="selected" class="badge" :class="selected.direction === 'in' ? 'badge-success' : 'badge-warning'">
                    {{ selected.direction === 'in' ? 'Entra' : 'Sale' }}
                </span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Almacén</dt>
                    <dd>{{ selected.warehouse_code }}</dd>
                </div>
                <div>
                    <dt>Cantidad</dt>
                    <dd>{{ quantity(selected.quantity) }} {{ uomCode ?? '' }}</dd>
                </div>
                <div>
                    <dt>Costo unitario (LC)</dt>
                    <dd>{{ money(selected.unit_cost_local) }}</dd>
                </div>
                <div>
                    <dt>Total (LC)</dt>
                    <dd>{{ money(selected.total_cost_local) }}</dd>
                </div>
                <div>
                    <dt>Total (FC)</dt>
                    <dd>{{ money(selected.total_cost_foreign) }}</dd>
                </div>
                <div>
                    <dt>Promedio tras el movimiento</dt>
                    <dd>{{ money(selected.avg_cost_local_after) }}</dd>
                </div>
                <div>
                    <dt>Saldo del almacén</dt>
                    <dd>{{ quantity(selected.balance_quantity) }}</dd>
                </div>
                <div>
                    <dt>Saldo del artículo</dt>
                    <dd>{{ quantity(selected.running) }}</dd>
                </div>
                <div>
                    <dt>Asiento</dt>
                    <dd>{{ selected.journal_document_number ? `#${selected.journal_document_number}` : '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link
                    v-if="selected?.inventory_document_id"
                    :href="route('inventory-movements.show', selected.inventory_document_id)"
                    class="btn btn-primary"
                ><EyeIcon /> Ver movimiento</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.opening-row td { background: var(--color-surface-alt); }

.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1.75rem;
    padding: 1rem 1.25rem;
    margin-bottom: 0.9rem;
}

.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }
.num-value { font-variant-numeric: tabular-nums; }

.kardex-hint { margin: 0.75rem 0 0; }

table { font-size: 0.85rem; }
.date-cell, .code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }

@media screen and (max-width: 1024px) {
    .opening-row td { background: none; }
    .table-responsive tbody tr.opening-row { background: var(--color-surface-alt); }
}
</style>
