<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    receipt: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    taxAccounts: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

// Estado por línea: qué se devuelve y a qué precio acredita el proveedor. El
// precio arranca en el costo de compra, que es el caso normal; cambiarlo abre
// la diferencia que la nota lleva a resultados.
const selection = reactive(Object.fromEntries(
    props.lines.map((line) => [line.id, {
        checked: false,
        quantity: line.pending,
        price: Number(line.unit_cost_local).toFixed(2),
    }])
));

const form = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    posting_date: today,
    tax_account_id: '',
    tax_amount: '',
    description: '',
    lines: [],
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const returnable = computed(() => props.lines.filter((line) => Number(line.pending) > 0));

const chosen = computed(() =>
    returnable.value.filter((line) => selection[line.id].checked)
);

const net = computed(() => chosen.value.reduce(
    (sum, line) => sum + Number(selection[line.id].quantity || 0) * Number(selection[line.id].price || 0), 0
));

const selectedTaxAccount = computed(() =>
    props.taxAccounts.find((a) => a.id === form.tax_account_id) ?? null
);

// El IVA se recalcula sobre lo devuelto: es la parte del impuesto de la factura
// original que el proveedor devuelve. Queda editable porque manda su documento.
watch([() => form.tax_account_id, net], ([accountId]) => {
    if (! accountId) {
        form.tax_amount = '';
        return;
    }

    form.tax_amount = (net.value * Number(selectedTaxAccount.value?.percentage ?? 0) / 100).toFixed(2);
});

const total = computed(() => net.value + Number(form.tax_amount || 0));

function exceeds(line) {
    return Number(selection[line.id].quantity || 0) > Number(line.pending);
}

const invalid = computed(() =>
    ! chosen.value.length
    || chosen.value.some((line) => Number(selection[line.id].quantity || 0) <= 0 || exceeds(line))
);

function submit() {
    form
        .transform((data) => ({
            ...data,
            tax_account_id: data.tax_account_id === '' ? null : data.tax_account_id,
            tax_amount: data.tax_amount === '' ? null : data.tax_amount,
            description: data.description === '' ? null : data.description,
            lines: chosen.value.map((line) => ({
                receipt_line_id: line.id,
                quantity: selection[line.id].quantity,
                credited_unit_price: selection[line.id].price,
            })),
        }))
        .post(route('supplier-credit-notes.store', props.receipt.id));
}
</script>

<template>
    <Head title="Nota de crédito de proveedor" />

    <AppLayout title="Nota de crédito de proveedor">
        <div v-if="page.props.errors?.credit_note" class="flash flash-error">{{ page.props.errors.credit_note }}</div>

        <p v-if="!documentTypes.length" class="flash flash-warning">
            Hace falta al menos un tipo de documento activo con módulo de origen "Compras" para emitir la nota.
        </p>

        <div class="card summary">
            <div>
                <span class="muted small">Recepción de origen</span>
                <Link :href="route('inventory-movements.show', receipt.id)" class="link">#{{ receipt.id }} del {{ receipt.posting_date }}</Link>
            </div>
            <div><span class="muted small">Proveedor</span><strong>{{ receipt.supplier }}</strong></div>
            <div>
                <span class="muted small">Factura que se acredita</span>
                <Link :href="route('journal-entries.show', receipt.invoice_journal_entry_id)" class="link">
                    #{{ receipt.invoice_document_number }}
                </Link>
            </div>
        </div>

        <p class="hint">
            La nota es el espejo exacto de la compra: la mercancía vuelve a salir del inventario contra la
            <strong>cuenta puente GR/IR</strong>, y la nota liquida esa puente reduciendo la deuda con el proveedor.
            Si lo que acredita difiere del costo al que entró, la diferencia va a resultados.
        </p>

        <form class="card" @submit.prevent="submit">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th class="right">Recibido</th>
                            <th class="right">Ya devuelto</th>
                            <th class="right">Devolver</th>
                            <th class="right">Precio acreditado</th>
                            <th class="right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in returnable" :key="line.id" :class="{ chosen: selection[line.id].checked }">
                            <td><input v-model="selection[line.id].checked" type="checkbox"></td>
                            <td>{{ line.item }}</td>
                            <td class="code-cell">{{ line.warehouse_code }}</td>
                            <td class="num right">{{ quantity(line.quantity) }}</td>
                            <td class="num right muted">{{ quantity(line.returned) }}</td>
                            <td class="right">
                                <input
                                    v-model="selection[line.id].quantity"
                                    type="number" step="0.000001" min="0" class="cell-input"
                                    :max="line.pending" :disabled="!selection[line.id].checked"
                                >
                                <span v-if="selection[line.id].checked && exceeds(line)" class="error">
                                    Quedan {{ quantity(line.pending) }} por devolver.
                                </span>
                            </td>
                            <td class="right">
                                <input
                                    v-model="selection[line.id].price"
                                    type="number" step="0.01" min="0" class="cell-input"
                                    :disabled="!selection[line.id].checked"
                                >
                            </td>
                            <td class="num right">
                                {{ selection[line.id].checked
                                    ? money(Number(selection[line.id].quantity || 0) * Number(selection[line.id].price || 0))
                                    : '—' }}
                            </td>
                        </tr>
                        <tr v-if="!returnable.length">
                            <td colspan="8" class="muted empty-row">
                                Esta recepción ya fue devuelta por completo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <span v-if="form.errors.lines" class="error">{{ form.errors.lines }}</span>

            <div class="grid-2">
                <div class="field">
                    <label>Tipo de documento</label>
                    <select v-model="form.document_type_id" required>
                        <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                    </select>
                    <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                </div>
                <div class="field">
                    <label>Fecha de contabilización</label>
                    <input v-model="form.posting_date" type="date" required>
                    <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                </div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label>Cuenta de IVA (opcional)</label>
                    <select v-model="form.tax_account_id">
                        <option value="">— Sin IVA —</option>
                        <option v-for="a in taxAccounts" :key="a.id" :value="a.id">
                            {{ a.label }} ({{ a.percentage }}%)
                        </option>
                    </select>
                    <span v-if="form.errors.tax_account_id" class="error">{{ form.errors.tax_account_id }}</span>
                </div>
                <div class="field">
                    <label>IVA devuelto</label>
                    <input v-model="form.tax_amount" type="number" step="0.01" min="0" :disabled="!form.tax_account_id">
                    <span v-if="form.errors.tax_amount" class="error">{{ form.errors.tax_amount }}</span>
                </div>
            </div>

            <div class="field">
                <label>Descripción (opcional)</label>
                <input v-model="form.description" type="text" maxlength="255">
            </div>

            <div class="totals">
                <div><span class="muted small">Neto acreditado</span><strong class="num">{{ money(net) }}</strong></div>
                <div><span class="muted small">IVA</span><strong class="num">{{ money(form.tax_amount || 0) }}</strong></div>
                <div><span class="muted small">Total de la nota</span><strong class="num total">{{ money(total) }}</strong></div>
            </div>

            <div class="actions">
                <Link :href="route('inventory-movements.show', receipt.id)" class="btn btn-ghost">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="invalid || form.processing || !documentTypes.length">
                    Emitir nota de crédito
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: var(--color-warning-soft); color: var(--color-warning); }
.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
}

.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }

.card { padding: 1rem 1.25rem; }
.table-scroll { overflow-x: auto; margin: 0 -1.25rem 1rem; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.chosen { background: var(--color-surface-muted, rgb(0 0 0 / 3%)); }
.cell-input { width: 8rem; text-align: right; }

.grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }
.error { color: var(--color-danger, #b91c1c); font-size: 0.76rem; }

.totals {
    display: flex;
    flex-wrap: wrap;
    gap: 1.75rem;
    padding: 0.85rem 0;
    border-top: 1px solid var(--color-border);
}

.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.05rem; }

.actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

@media (max-width: 640px) {
    .grid-2 { grid-template-columns: 1fr; }
}
</style>
