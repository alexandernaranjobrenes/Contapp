<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { ArrowLeftIcon, CheckIcon, PlusIcon, XIcon } from '@lucide/vue';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

function blankLine() {
    return { item_id: '', warehouse_id: '', quantity: '', unit_cost_local: '', description: '' };
}

const form = useForm({
    business_partner_id: '',
    order_date: today,
    expected_date: '',
    description: '',
    lines: [blankLine()],
});

function addLine() {
    form.lines.push(blankLine());
}

function removeLine(index) {
    if (form.lines.length === 1) return;
    form.lines.splice(index, 1);
}

// Sugerencia al elegir artículo: su costo promedio actual. Es una ayuda para
// digitar, no el costo real — ese lo fija la recepción.
function suggestCost(line) {
    const item = props.items.find((i) => i.id === line.item_id);

    if (item && line.unit_cost_local === '') {
        line.unit_cost_local = Number(item.avg_cost_local).toFixed(2);
    }
}

const ready = () => props.suppliers.length && props.items.length && props.warehouses.length;

function submit() {
    form.transform((data) => ({
        ...data,
        expected_date: data.expected_date === '' ? null : data.expected_date,
        description: data.description === '' ? null : data.description,
        lines: data.lines.map((l) => ({
            ...l,
            unit_cost_local: l.unit_cost_local === '' ? 0 : l.unit_cost_local,
            description: l.description === '' ? null : l.description,
        })),
    })).post(route('purchase-orders.store'));
}
</script>

<template>
    <Head title="Nueva orden de compra" />

    <AppLayout title="Nueva orden de compra">
        <div class="view-toolbar">
            <Link :href="route('purchase-orders.index')" class="btn btn-ghost"><ArrowLeftIcon /> Órdenes de compra</Link>
        </div>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p v-if="!ready()" class="flash flash-warning">
            Hacen falta al menos un proveedor activo, un artículo comprable de inventario y un almacén activo.
        </p>

        <p class="hint">
            La orden no contabiliza nada: solo declara qué viene en camino. El costo que digités es el
            <strong>pactado</strong> y es informativo — el costo real al que entra la mercancía lo fija la recepción.
        </p>

        <form class="card form-card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label for="po-supplier">Proveedor</label>
                    <select id="po-supplier" v-model="form.business_partner_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                    </select>
                    <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                </div>

                <div class="field">
                    <label for="po-date">Fecha de la orden</label>
                    <input id="po-date" v-model="form.order_date" type="date" required>
                </div>

                <div class="field">
                    <label for="po-expected">Fecha esperada (opcional)</label>
                    <input id="po-expected" v-model="form.expected_date" type="date">
                </div>

                <div class="field">
                    <label for="po-description">Descripción (opcional)</label>
                    <input id="po-description" v-model="form.description" type="text" maxlength="255">
                </div>
            </div>

            <!-- Grilla de captura (CLAUDE.md secc. 20, excepción): cada línea se
                 edita en la tabla y conserva su botón de quitar. -->
            <div class="table-responsive capture-grid">
                <table class="lines-table">
                    <colgroup>
                        <col>
                        <col class="col-warehouse">
                        <col class="col-qty">
                        <col class="col-cost">
                        <col class="col-detail">
                        <col class="col-remove">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén destino</th>
                            <th class="num">Cantidad</th>
                            <th class="num">Costo pactado</th>
                            <th>Detalle</th>
                            <th><span class="sr-only">Quitar</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.lines" :key="index">
                            <td>
                                <select v-model="line.item_id" required aria-label="Artículo" @change="suggestCost(line)">
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                </select>
                            </td>
                            <td data-label="Almacén destino">
                                <select v-model="line.warehouse_id" required aria-label="Almacén destino">
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                </select>
                            </td>
                            <td data-label="Cantidad" class="num">
                                <input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="num-input" aria-label="Cantidad">
                            </td>
                            <td data-label="Costo pactado" class="num">
                                <input v-model="line.unit_cost_local" type="number" step="0.000001" min="0" class="num-input" aria-label="Costo pactado">
                            </td>
                            <td data-label="Detalle"><input v-model="line.description" type="text" maxlength="255" aria-label="Detalle"></td>
                            <td class="remove-cell">
                                <button type="button" class="btn btn-ghost remove-btn" :disabled="form.lines.length === 1" aria-label="Quitar línea" title="Quitar línea" @click="removeLine(index)"><XIcon /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="form-actions lines-actions">
                <button type="button" class="btn btn-ghost" @click="addLine"><PlusIcon /> Agregar línea</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !ready()"><CheckIcon /> Crear orden</button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; }
.lines-table { table-layout: fixed; font-size: 0.85rem; }
.lines-table th, .lines-table td { padding: 0.4rem 0.5rem; }
.col-warehouse { width: 7.5rem; }
.col-qty { width: 7rem; }
.col-cost { width: 8rem; }
.col-detail { width: 18%; }
.col-remove { width: 2.75rem; }
.lines-table select, .lines-table input { width: 100%; min-width: 0; }
.num-input { text-align: right; }
.remove-btn { width: 2.25rem; padding: 0; }
.lines-actions { justify-content: space-between; margin-top: 1rem; }

@media screen and (max-width: 1024px) {
    .remove-cell { justify-content: flex-end; }
}
</style>
