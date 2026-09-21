<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
        <template #actions>
            <Link :href="route('purchase-orders.index')" class="btn btn-ghost">Ver órdenes</Link>
        </template>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p v-if="!ready()" class="flash flash-warning">
            Hacen falta al menos un proveedor activo, un artículo comprable de inventario y un almacén activo.
        </p>

        <p class="hint">
            La orden no contabiliza nada: solo declara qué viene en camino. El costo que digités es el
            <strong>pactado</strong> y es informativo — el costo real al que entra la mercancía lo fija la recepción.
        </p>

        <form class="card form-card" @submit.prevent="submit">
            <div class="grid-4">
                <div class="field">
                    <label>Proveedor</label>
                    <select v-model="form.business_partner_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                    </select>
                    <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                </div>

                <div class="field">
                    <label>Fecha de la orden</label>
                    <input v-model="form.order_date" type="date" required>
                </div>

                <div class="field">
                    <label>Fecha esperada (opcional)</label>
                    <input v-model="form.expected_date" type="date">
                </div>

                <div class="field">
                    <label>Descripción (opcional)</label>
                    <input v-model="form.description" type="text" maxlength="255">
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th>Almacén destino</th>
                        <th class="num">Cantidad</th>
                        <th class="num">Costo pactado</th>
                        <th>Detalle</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in form.lines" :key="index">
                        <td>
                            <select v-model="line.item_id" required @change="suggestCost(line)">
                                <option value="" disabled>— Elegir —</option>
                                <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                            </select>
                        </td>
                        <td>
                            <select v-model="line.warehouse_id" required>
                                <option value="" disabled>— Elegir —</option>
                                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                            </select>
                        </td>
                        <td>
                            <input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="right">
                        </td>
                        <td>
                            <input v-model="line.unit_cost_local" type="number" step="0.000001" min="0" class="right">
                        </td>
                        <td><input v-model="line.description" type="text" maxlength="255"></td>
                        <td>
                            <button type="button" class="btn btn-ghost" @click="removeLine(index)">Quitar</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="form-actions">
                <button type="button" class="btn btn-ghost" @click="addLine">+ Agregar línea</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !ready()">Crear orden</button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.num, .right { text-align: right; }
.form-actions { display: flex; gap: 0.75rem; align-items: center; padding-top: 0.75rem; }
</style>
