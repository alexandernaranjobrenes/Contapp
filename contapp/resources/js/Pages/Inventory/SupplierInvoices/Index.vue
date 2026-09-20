<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    pending: { type: Array, default: () => [] },
    invoiceTypes: { type: Array, default: () => [] },
    taxAccounts: { type: Array, default: () => [] },
    // Recepción señalada por el "Copiar a" de su documento de entrada.
    preselected: { type: Number, default: null },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const invoicing = ref(null);

const form = useForm({
    inventory_document_id: null,
    document_type_id: props.invoiceTypes[0]?.id ?? '',
    document_date: today,
    posting_date: today,
    due_date: '',
    tax_account_id: '',
    tax_amount: '',
    net_amount: '',
    description: '',
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function openInvoice(receipt) {
    form.clearErrors();
    form.inventory_document_id = receipt.id;
    form.document_date = today;
    form.posting_date = today;
    form.due_date = '';
    form.tax_account_id = '';
    form.tax_amount = '';
    form.net_amount = receipt.total_local.toFixed(2);
    form.description = '';
    invoicing.value = receipt;
}

// Llegar desde el "Copiar a" de una recepción equivale a pulsar su botón
// "Facturar": el formulario es el mismo, solo que ya viene apuntado.
if (props.preselected) {
    const receipt = props.pending.find((r) => r.id === props.preselected);

    if (receipt) {
        openInvoice(receipt);
    }
}

const selectedTaxAccount = computed(() =>
    props.taxAccounts.find((a) => a.id === form.tax_account_id) ?? null
);

// El IVA se precalcula sobre el neto de la recepción al elegir la cuenta,
// pero queda editable: la factura del proveedor manda, y PostJournalService
// rechaza el asiento si el monto no corresponde a base × tarifa.
watch(() => form.tax_account_id, (accountId) => {
    if (! accountId || ! invoicing.value) {
        form.tax_amount = '';
        return;
    }

    const percentage = Number(selectedTaxAccount.value?.percentage ?? 0);
    form.tax_amount = (net.value * percentage / 100).toFixed(2);
});

const net = computed(() =>
    form.net_amount === '' ? (invoicing.value?.total_local ?? 0) : Number(form.net_amount)
);

const variance = computed(() =>
    invoicing.value ? net.value - invoicing.value.total_local : 0
);

const total = computed(() => net.value + Number(form.tax_amount || 0));

function submit() {
    form
        .transform((data) => ({
            ...data,
            net_amount: data.net_amount === '' ? null : data.net_amount,
            tax_account_id: data.tax_account_id === '' ? null : data.tax_account_id,
            tax_amount: data.tax_amount === '' ? null : data.tax_amount,
            due_date: data.due_date === '' ? null : data.due_date,
            description: data.description === '' ? null : data.description,
        }))
        .post(route('supplier-invoices.store'), { onSuccess: () => (invoicing.value = null) });
}
</script>

<template>
    <Head title="Facturas de proveedor" />

    <AppLayout title="Recepciones pendientes de facturar">
        <div v-if="page.props.errors?.invoice" class="flash flash-error">{{ page.props.errors.invoice }}</div>

        <p v-if="!invoiceTypes.length" class="flash flash-warning">
            Hace falta al menos un tipo de documento activo con módulo de origen "Compras" para poder facturar.
        </p>

        <p class="hint">
            Cada fila es una entrada por compra ya recibida cuya deuda sigue en la <strong>cuenta puente GR/IR</strong>.
            Facturarla convierte ese pasivo provisional en la deuda real con el proveedor y abre la partida pendiente en
            Cuentas por Pagar. Si el proveedor factura un neto distinto al recibido, la diferencia se capitaliza al costo
            —en la proporción que siga en existencia— y el resto va a diferencia de precio.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ pending.length }} recepción(es) pendiente(s)</span>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Asiento</th>
                            <th>Proveedor</th>
                            <th class="right">Líneas</th>
                            <th class="right">Valor recibido</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in pending" :key="r.id">
                            <td class="num">{{ r.posting_date }}</td>
                            <td class="code-cell">{{ r.document_type_code }}</td>
                            <td class="num muted small">#{{ r.journal_document_number }}</td>
                            <td>{{ r.supplier }}</td>
                            <td class="num right">{{ r.lines_count }}</td>
                            <td class="num right">{{ money(r.total_local) }}</td>
                            <td>
                                <button
                                    type="button" class="btn btn-primary"
                                    :disabled="!invoiceTypes.length"
                                    @click="openInvoice(r)"
                                >
                                    Facturar
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!pending.length">
                            <td colspan="7" class="muted empty-row">
                                No hay recepciones pendientes: toda la mercancía recibida ya está facturada.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="invoicing" class="modal-backdrop" @click.self="invoicing = null">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>Factura de {{ invoicing.supplier }}</h2>
                <p class="muted small">Liquida la recepción del {{ invoicing.posting_date }} por {{ money(invoicing.total_local) }}.</p>

                <div class="grid-2">
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="form.document_type_id" required>
                            <option v-for="t in invoiceTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                        <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                    </div>
                    <div class="field">
                        <label>Vencimiento (opcional)</label>
                        <input v-model="form.due_date" type="date">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Fecha de la factura</label>
                        <input v-model="form.document_date" type="date" required>
                    </div>
                    <div class="field">
                        <label>Fecha de contabilización</label>
                        <input v-model="form.posting_date" type="date" required>
                        <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                    </div>
                </div>

                <div class="field">
                    <label>Neto facturado (sin IVA)</label>
                    <input v-model="form.net_amount" type="number" step="0.01" min="0.01" required>
                    <span v-if="form.errors.net_amount" class="error">{{ form.errors.net_amount }}</span>
                    <span v-if="variance !== 0" class="variance">
                        Difiere en {{ money(variance) }} de lo recibido: la parte con existencia se capitaliza al costo,
                        el resto va a diferencia de precio.
                    </span>
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
                        <label>Monto de IVA</label>
                        <input v-model="form.tax_amount" type="number" step="0.01" min="0" :disabled="!form.tax_account_id">
                        <span v-if="form.errors.tax_amount" class="error">{{ form.errors.tax_amount }}</span>
                    </div>
                </div>

                <div class="field">
                    <label>Descripción (opcional)</label>
                    <input v-model="form.description" type="text" maxlength="255">
                </div>

                <div class="totals">
                    <div><span class="muted small">Recibido (cuenta puente)</span><strong class="num">{{ money(invoicing.total_local) }}</strong></div>
                    <div><span class="muted small">Neto facturado</span><strong class="num">{{ money(net) }}</strong></div>
                    <div><span class="muted small">IVA</span><strong class="num">{{ money(form.tax_amount || 0) }}</strong></div>
                    <div><span class="muted small">Total a pagar</span><strong class="num total">{{ money(total) }}</strong></div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Contabilizar factura</button>
                    <button type="button" class="btn btn-ghost" @click="invoicing = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: var(--color-warning-soft); color: var(--color-warning); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: -0.25rem 0 1rem; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card { width: 620px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.25rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.field input:disabled { opacity: 0.5; }

.totals {
    display: flex;
    justify-content: flex-end;
    gap: 1.75rem;
    padding: 0.75rem 0;
    border-top: 1px solid var(--color-border);
    margin-top: 0.5rem;
}

.totals > div { display: flex; flex-direction: column; align-items: flex-end; gap: 0.15rem; }
.totals .total { font-size: 1.05rem; }

.error { color: var(--color-danger); font-size: 0.76rem; }
.variance { color: var(--color-warning); font-size: 0.76rem; }
.modal-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; }
</style>
