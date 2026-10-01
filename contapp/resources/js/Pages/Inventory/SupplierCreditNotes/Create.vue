<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { ArrowLeftIcon, CheckIcon } from '@lucide/vue';
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
        <div class="view-toolbar">
            <Link :href="route('inventory-movements.show', receipt.id)" class="btn btn-ghost"><ArrowLeftIcon /> Recepción de origen</Link>
        </div>

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

        <form @submit.prevent="submit">
            <!-- Grilla de selección: la casilla elige qué se devuelve y la
                 cantidad y el precio se ajustan en la fila (CLAUDE.md secc. 20,
                 excepción). -->
            <div class="card">
                <div class="table-responsive capture-grid">
                    <table>
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th class="num">Recibido</th>
                                <th class="num">Devolver</th>
                                <th class="num">Precio acreditado</th>
                                <th class="num">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in returnable" :key="line.id" :class="{ chosen: selection[line.id].checked }">
                                <td>
                                    <label class="pick-cell">
                                        <input v-model="selection[line.id].checked" type="checkbox">
                                        <span>
                                            {{ line.item }}
                                            <span class="breakdown">almacén {{ line.warehouse_code }}</span>
                                        </span>
                                    </label>
                                </td>
                                <td data-label="Recibido" class="num">
                                    {{ quantity(line.quantity) }}
                                    <span class="breakdown">ya devuelto {{ quantity(line.returned) }}</span>
                                </td>
                                <td data-label="Devolver" class="num">
                                    <input
                                        v-model="selection[line.id].quantity"
                                        type="number" step="0.000001" min="0" class="cell-input"
                                        :max="line.pending" :disabled="!selection[line.id].checked"
                                        aria-label="Cantidad a devolver"
                                    >
                                    <span v-if="selection[line.id].checked && exceeds(line)" class="error-text">
                                        Quedan {{ quantity(line.pending) }} por devolver.
                                    </span>
                                </td>
                                <td data-label="Precio acreditado" class="num">
                                    <input
                                        v-model="selection[line.id].price"
                                        type="number" step="0.01" min="0" class="cell-input"
                                        :disabled="!selection[line.id].checked"
                                        aria-label="Precio acreditado"
                                    >
                                </td>
                                <td data-label="Subtotal" class="num">
                                    {{ selection[line.id].checked
                                        ? money(Number(selection[line.id].quantity || 0) * Number(selection[line.id].price || 0))
                                        : '—' }}
                                </td>
                            </tr>
                            <tr v-if="!returnable.length">
                                <td colspan="5" class="muted empty-row">
                                    Esta recepción ya fue devuelta por completo.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <span v-if="form.errors.lines" class="error-text">{{ form.errors.lines }}</span>

            <div class="card form-card">
            <div class="form-grid">
                <div class="field">
                    <label for="scn-doc-type">Tipo de documento</label>
                    <select id="scn-doc-type" v-model="form.document_type_id" required>
                        <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                    </select>
                    <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                </div>
                <div class="field">
                    <label for="scn-date">Fecha de contabilización</label>
                    <input id="scn-date" v-model="form.posting_date" type="date" required>
                    <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                </div>
                <div class="field">
                    <label for="scn-tax-account">Cuenta de IVA (opcional)</label>
                    <select id="scn-tax-account" v-model="form.tax_account_id">
                        <option value="">— Sin IVA —</option>
                        <option v-for="a in taxAccounts" :key="a.id" :value="a.id">
                            {{ a.label }} ({{ a.percentage }}%)
                        </option>
                    </select>
                    <span v-if="form.errors.tax_account_id" class="error">{{ form.errors.tax_account_id }}</span>
                </div>
                <div class="field">
                    <label for="scn-tax">IVA devuelto</label>
                    <input id="scn-tax" v-model="form.tax_amount" type="number" step="0.01" min="0" :disabled="!form.tax_account_id">
                    <span v-if="form.errors.tax_amount" class="error">{{ form.errors.tax_amount }}</span>
                </div>
                <div class="field span-full">
                    <label for="scn-description">Descripción (opcional)</label>
                    <input id="scn-description" v-model="form.description" type="text" maxlength="255">
                </div>
            </div>

            <div class="totals">
                <div><span class="muted small">Neto acreditado</span><strong class="num-value">{{ money(net) }}</strong></div>
                <div><span class="muted small">IVA</span><strong class="num-value">{{ money(form.tax_amount || 0) }}</strong></div>
                <div><span class="muted small">Total de la nota</span><strong class="num-value total">{{ money(total) }}</strong></div>
            </div>

            <div class="form-actions">
                <Link :href="route('inventory-movements.show', receipt.id)" class="btn btn-ghost">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="invalid || form.processing || !documentTypes.length">
                    <CheckIcon /> Emitir nota de crédito
                </button>
            </div>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
}

.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }

table { font-size: 0.85rem; }
.pick-cell { display: inline-flex; align-items: flex-start; gap: 0.5rem; }
.pick-cell input { margin-top: 0.2rem; }
.breakdown { display: block; font-size: 0.7rem; color: var(--color-text-muted); font-weight: 400; }
.chosen td { background: var(--color-primary-soft); }
.cell-input { width: 8rem; text-align: right; }
.form-card { margin-top: 0.9rem; padding: 1rem 1.25rem; }
.num-value { font-variant-numeric: tabular-nums; }

.totals {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1.75rem;
    padding: 0.85rem 0;
    border-top: 1px solid var(--color-border);
}

.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.05rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

@media screen and (max-width: 1024px) {
    .chosen td { background: none; }
    .table-responsive tbody tr.chosen { background: var(--color-primary-soft); }
    .cell-input { width: auto; }
    .breakdown { display: inline; margin-left: 0.4rem; }
}
</style>
