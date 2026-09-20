<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    stock: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    business_partner_id: props.customers[0]?.id ?? '',
    order_date: today,
    delivery_date: '',
    description: '',
    lines: [emptyLine()],
});

function emptyLine() {
    return {
        item_id: props.items[0]?.id ?? '',
        warehouse_id: props.warehouses[0]?.id ?? '',
        quantity: '',
        unit_price: '',
        description: '',
    };
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Lo libre es lo que decide si el pedido se puede tomar: la existencia menos
// lo que otros pedidos ya apartaron.
function freeFor(line) {
    const row = props.stock.find(
        (s) => s.item_id === Number(line.item_id) && s.warehouse_id === Number(line.warehouse_id)
    );

    return row ? row.free : 0;
}

function exceeds(line) {
    return Number(line.quantity || 0) > freeFor(line);
}

const total = computed(() => form.lines.reduce(
    (sum, line) => sum + Number(line.quantity || 0) * Number(line.unit_price || 0), 0
));

const invalid = computed(() =>
    ! form.lines.length
    || form.lines.some((line) => ! line.item_id || ! line.warehouse_id || Number(line.quantity || 0) <= 0 || exceeds(line))
);

function submit() {
    form
        .transform((data) => ({
            ...data,
            delivery_date: data.delivery_date === '' ? null : data.delivery_date,
            description: data.description === '' ? null : data.description,
            lines: data.lines.map((line) => ({
                ...line,
                unit_price: line.unit_price === '' ? null : line.unit_price,
                description: line.description === '' ? null : line.description,
            })),
        }))
        .post(route('sales-orders.store'));
}
</script>

<template>
    <Head title="Nuevo pedido" />

    <AppLayout title="Nueva orden de pedido">
        <div v-if="page.props.errors?.order" class="flash flash-error">{{ page.props.errors.order }}</div>

        <p class="hint">
            El pedido no contabiliza nada: solo aparta la mercancía para este cliente. Se puede apartar
            únicamente lo que esté <strong>libre</strong> —la existencia menos lo que otros pedidos ya
            comprometieron—, porque prometer lo que no hay no es apartar.
        </p>

        <form class="card" @submit.prevent="submit">
            <div class="grid-3">
                <div class="field">
                    <label>Cliente</label>
                    <select v-model="form.business_partner_id" required>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                    <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                </div>
                <div class="field">
                    <label>Fecha del pedido</label>
                    <input v-model="form.order_date" type="date" required>
                </div>
                <div class="field">
                    <label>Fecha de entrega (opcional)</label>
                    <input v-model="form.delivery_date" type="date">
                </div>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Bodega</th>
                            <th class="right">Disponible</th>
                            <th class="right">Cantidad</th>
                            <th class="right">Precio pactado</th>
                            <th class="right">Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.lines" :key="index">
                            <td>
                                <select v-model="line.item_id" required>
                                    <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                </select>
                            </td>
                            <td>
                                <select v-model="line.warehouse_id" required>
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                </select>
                            </td>
                            <td class="num right" :class="{ none: freeFor(line) <= 0 }">{{ quantity(freeFor(line)) }}</td>
                            <td class="right">
                                <input v-model="line.quantity" type="number" step="0.000001" min="0" class="cell-input" required>
                                <span v-if="exceeds(line)" class="error">Solo hay {{ quantity(freeFor(line)) }} libres.</span>
                            </td>
                            <td class="right">
                                <input v-model="line.unit_price" type="number" step="0.01" min="0" class="cell-input">
                            </td>
                            <td class="num right">
                                {{ money(Number(line.quantity || 0) * Number(line.unit_price || 0)) }}
                            </td>
                            <td>
                                <button
                                    type="button" class="btn btn-ghost small-btn"
                                    :disabled="form.lines.length === 1"
                                    @click="form.lines.splice(index, 1)"
                                >
                                    Quitar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn btn-ghost" @click="form.lines.push(emptyLine())">Agregar línea</button>

            <div class="field">
                <label>Descripción (opcional)</label>
                <input v-model="form.description" type="text" maxlength="255">
            </div>

            <div class="totals">
                <div><span class="muted small">Total pactado (informativo)</span><strong class="num total">{{ money(total) }}</strong></div>
            </div>

            <div class="actions">
                <Link :href="route('sales-orders.index')" class="btn btn-ghost">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="invalid || form.processing">
                    Registrar pedido y apartar
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.card { padding: 1rem 1.25rem; }

.grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }
.error { color: var(--color-danger, #b91c1c); font-size: 0.76rem; }

.table-scroll { overflow-x: auto; margin: 0 -1.25rem 0.75rem; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.none { color: var(--color-danger, #b91c1c); }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.cell-input { width: 7rem; text-align: right; }
.small-btn { font-size: 0.76rem; padding: 0.2rem 0.5rem; }

.totals { display: flex; gap: 1.75rem; padding: 0.85rem 0; border-top: 1px solid var(--color-border); }
.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.05rem; }

.actions { display: flex; justify-content: flex-end; gap: 0.5rem; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

@media (max-width: 720px) {
    .grid-3 { grid-template-columns: 1fr; }
}
</style>
