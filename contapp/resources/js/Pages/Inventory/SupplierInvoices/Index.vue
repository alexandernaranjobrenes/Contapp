<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { CheckIcon, ReceiptIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';

const props = defineProps({
    pending: { type: Array, default: () => [] },
    invoiceTypes: { type: Array, default: () => [] },
    taxAccounts: { type: Array, default: () => [] },
    // Recepción señalada por el "Copiar a" de su documento de entrada.
    preselected: { type: Number, default: null },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

// Recepción abierta en la ficha y modo del modal: 'details' la muestra,
// 'invoice' la factura.
const selectedId = ref(null);
const mode = ref('details');
const selected = computed(() => props.pending.find((r) => r.id === selectedId.value) ?? null);
const invoicing = computed(() => (mode.value === 'invoice' ? selected.value : null));

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

function openReceipt(receipt) {
    selectedId.value = receipt.id;
    mode.value = 'details';
}

function closeReceipt() {
    selectedId.value = null;
    mode.value = 'details';
}

function startInvoice() {
    fillInvoice(selected.value);
    mode.value = 'invoice';
}

function openInvoice(receipt) {
    selectedId.value = receipt.id;
    fillInvoice(receipt);
    mode.value = 'invoice';
}

function fillInvoice(receipt) {
    form.clearErrors();
    form.inventory_document_id = receipt.id;
    form.document_date = today;
    form.posting_date = today;
    form.due_date = '';
    form.tax_account_id = '';
    form.tax_amount = '';
    form.net_amount = receipt.total_local.toFixed(2);
    form.description = '';
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
        .post(route('supplier-invoices.store'), { onSuccess: closeReceipt });
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

        <p class="muted small list-count">{{ pending.length }} recepción(es) pendiente(s)</p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th class="num">Líneas</th>
                            <th class="num">Valor recibido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in pending"
                            :key="r.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openReceipt(r)"
                            @keydown.enter="openReceipt(r)"
                            @keydown.space.prevent="openReceipt(r)"
                        >
                            <td class="code-cell">{{ r.posting_date }}</td>
                            <td data-label="Proveedor">{{ r.supplier }}</td>
                            <td data-label="Líneas" class="num">{{ r.lines_count }}</td>
                            <td data-label="Valor recibido" class="num">{{ money(r.total_local) }}</td>
                        </tr>
                        <tr v-if="!pending.length">
                            <td colspan="4" class="muted empty-row">
                                No hay recepciones pendientes: toda la mercancía recibida ya está facturada.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ficha de la recepción (CLAUDE.md secc. 20): «Facturar» pasa el
             mismo modal al formulario de la factura del proveedor. -->
        <DetailModal
            :open="!!selected"
            :title="selected ? (mode === 'invoice' ? `Factura de ${selected.supplier}` : `Recepción del ${selected.posting_date}`) : ''"
            @close="closeReceipt"
        >
            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div class="full">
                        <dt>Proveedor</dt>
                        <dd>{{ selected.supplier }}</dd>
                    </div>
                    <div>
                        <dt>Tipo de documento</dt>
                        <dd>{{ selected.document_type_code }}</dd>
                    </div>
                    <div>
                        <dt>Asiento</dt>
                        <dd>#{{ selected.journal_document_number }}</dd>
                    </div>
                    <div>
                        <dt>Líneas</dt>
                        <dd>{{ selected.lines_count }}</dd>
                    </div>
                    <div>
                        <dt>Valor recibido</dt>
                        <dd>{{ money(selected.total_local) }}</dd>
                    </div>
                </dl>
            </template>

            <form v-if="selected && mode === 'invoice'" id="supplier-invoice-form" @submit.prevent="submit">
                <p class="muted small">Liquida la recepción del {{ selected.posting_date }} por {{ money(selected.total_local) }}.</p>

                <div class="field-row">
                    <div class="field">
                        <label for="si-doc-type">Tipo de documento</label>
                        <select id="si-doc-type" v-model="form.document_type_id" required>
                            <option v-for="t in invoiceTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                        <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                    </div>
                    <div class="field">
                        <label for="si-due">Vencimiento (opcional)</label>
                        <input id="si-due" v-model="form.due_date" type="date">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="si-doc-date">Fecha de la factura</label>
                        <input id="si-doc-date" v-model="form.document_date" type="date" required>
                    </div>
                    <div class="field">
                        <label for="si-posting">Fecha de contabilización</label>
                        <input id="si-posting" v-model="form.posting_date" type="date" required>
                        <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="si-net">Neto facturado (sin IVA)</label>
                    <input id="si-net" v-model="form.net_amount" type="number" step="0.01" min="0.01" required>
                    <span v-if="form.errors.net_amount" class="error">{{ form.errors.net_amount }}</span>
                    <span v-if="variance !== 0" class="variance">
                        Difiere en {{ money(variance) }} de lo recibido: la parte con existencia se capitaliza al costo,
                        el resto va a diferencia de precio.
                    </span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="si-tax-account">Cuenta de IVA (opcional)</label>
                        <select id="si-tax-account" v-model="form.tax_account_id">
                            <option value="">— Sin IVA —</option>
                            <option v-for="a in taxAccounts" :key="a.id" :value="a.id">
                                {{ a.label }} ({{ a.percentage }}%)
                            </option>
                        </select>
                        <span v-if="form.errors.tax_account_id" class="error">{{ form.errors.tax_account_id }}</span>
                    </div>
                    <div class="field">
                        <label for="si-tax-amount">Monto de IVA</label>
                        <input id="si-tax-amount" v-model="form.tax_amount" type="number" step="0.01" min="0" :disabled="!form.tax_account_id">
                        <span v-if="form.errors.tax_amount" class="error">{{ form.errors.tax_amount }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="si-description">Descripción (opcional)</label>
                    <input id="si-description" v-model="form.description" type="text" maxlength="255">
                </div>

                <div class="totals">
                    <div><span class="muted small">Recibido (cuenta puente)</span><strong class="num-value">{{ money(selected.total_local) }}</strong></div>
                    <div><span class="muted small">Neto facturado</span><strong class="num-value">{{ money(net) }}</strong></div>
                    <div><span class="muted small">IVA</span><strong class="num-value">{{ money(form.tax_amount || 0) }}</strong></div>
                    <div><span class="muted small">Total a pagar</span><strong class="num-value total">{{ money(total) }}</strong></div>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-primary" :disabled="!invoiceTypes.length" @click="startInvoice"><ReceiptIcon /> Facturar</button>
                </template>
                <template v-else-if="selected">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="supplier-invoice-form" class="btn btn-primary" :disabled="form.processing"><CheckIcon /> Contabilizar factura</button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.list-count { margin: 0 0 0.5rem; }

.totals {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.75rem 1.75rem;
    padding: 0.75rem 0 0;
    border-top: 1px solid var(--color-border);
    margin-top: 0.5rem;
}

.totals > div { display: flex; flex-direction: column; align-items: flex-end; gap: 0.15rem; }
.totals .total { font-size: 1.05rem; }
.num-value { font-variant-numeric: tabular-nums; }
.variance { color: var(--color-warning); font-size: 0.76rem; }
</style>
