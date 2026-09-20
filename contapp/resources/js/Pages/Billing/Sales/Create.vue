<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    catalogs: { type: Object, required: true },
    documentTypes: { type: Array, default: () => [] },
    activities: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    bins: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    hacienda: { type: Object, default: () => ({}) },
    // "Copiar a": pedido que esta factura cumple, o comprobante que esta nota
    // de crédito corrige. Solo uno de los dos viene lleno.
    sourceOrder: { type: Object, default: null },
    sourceDocument: { type: Object, default: null },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const localCurrency = props.currencies.find((c) => c.code === 'CRC') ?? props.currencies[0];

const form = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    fiscal_document_type: '01',
    situation: '1',
    branch: '001',
    terminal: '00001',
    business_partner_id: '',
    emitter_activity_code: props.activities.find((a) => a.is_default)?.code ?? props.activities[0]?.code ?? '',
    receiver_activity_code: '',
    currency_id: localCurrency?.id ?? '',
    exchange_rate: 1,
    sale_condition: '01',
    credit_term_days: '',
    document_date: today,
    posting_date: today,
    notes: '',
    lines: [blankLine()],
    payments: [],
    references: [],
    // Enlaces internos del ciclo; los llena el "Copiar a" de su origen.
    sales_order_id: null,
    original_sales_document_id: null,
});

function blankLine() {
    return {
        item_id: '', warehouse_id: '', warehouse_bin_id: '', item_code: '',
        cabys_code: '', description: '', unit_code: 'Unid', is_service: false,
        quantity: 1, unit_price: '', discount_code: '', discount_reason: '', discount_amount: 0,
        vin_or_serial: '',
        taxes: [{ tax_code: '01', iva_rate_code: '08' }],
    };
}

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// --- "Copiar a": el documento llega armado desde su origen ---

function lineFromSource(source) {
    return {
        ...blankLine(),
        item_id: source.item_id ?? '',
        warehouse_id: source.warehouse_id ?? '',
        item_code: source.item_code ?? '',
        cabys_code: source.cabys_code ?? '',
        description: source.description ?? '',
        unit_code: source.unit_code || 'Unid',
        is_service: source.is_service ?? false,
        quantity: source.quantity,
        unit_price: source.unit_price,
        taxes: [{ tax_code: '01', iva_rate_code: source.iva_rate_code || '08' }],
    };
}

if (props.sourceOrder) {
    form.business_partner_id = props.sourceOrder.business_partner_id;
    form.sales_order_id = props.sourceOrder.id;
    form.lines = props.sourceOrder.lines.map(lineFromSource);
}

if (props.sourceDocument) {
    // Una nota de crédito hereda del comprobante que corrige todo lo que la
    // hace ser "la misma venta al revés": cliente, condición y moneda.
    form.fiscal_document_type = '03';
    form.business_partner_id = props.sourceDocument.business_partner_id;
    form.sale_condition = props.sourceDocument.sale_condition;
    form.credit_term_days = props.sourceDocument.credit_term_days ?? '';
    form.currency_id = props.sourceDocument.currency_id;
    form.original_sales_document_id = props.sourceDocument.id;
    form.lines = props.sourceDocument.lines.map(lineFromSource);
    form.references = [{
        document_type: props.sourceDocument.fiscal_document_type,
        number: props.sourceDocument.clave,
        issued_at: props.sourceDocument.document_date,
        reason_code: '01',
        reason: 'Devolución de mercancía',
    }];
}

// --- Panel 1: partes ---

const selectedCustomer = computed(() => props.customers.find((c) => c.id === form.business_partner_id) ?? null);

// Un tiquete electrónico es el único que puede emitirse a consumidor final.
const receiverRequired = computed(() => form.fiscal_document_type !== '04');

watch(() => form.business_partner_id, (id) => {
    const customer = props.customers.find((c) => c.id === id);
    if (! customer) return;

    form.receiver_activity_code = customer.economic_activity_code ?? '';

    if (customer.payment_terms_days && isCredit.value) {
        form.credit_term_days = customer.payment_terms_days;
    }
});

// --- Panel 2: términos financieros ---

const isCredit = computed(() => props.catalogs.creditConditions.includes(form.sale_condition));

const isForeignCurrency = computed(() =>
    props.currencies.find((c) => c.id === form.currency_id)?.code !== 'CRC'
);

watch(() => form.currency_id, () => {
    if (! isForeignCurrency.value) form.exchange_rate = 1;
});

watch(isCredit, (credit) => {
    if (! credit) form.credit_term_days = '';
});

// --- Panel 3: líneas ---

function onItemSelected(line) {
    const item = props.items.find((i) => i.id === line.item_id);
    if (! item) return;

    // Los datos fiscales se copian del maestro pero quedan editables: el
    // comprobante debe poder reconstruirse tal cual se emitió aunque el
    // artículo cambie después.
    line.item_code = item.code;
    line.description = item.name;
    line.cabys_code = item.cabys_code ?? '';
    line.unit_code = item.fiscal_unit_code ?? 'Unid';
    line.is_service = ! item.is_inventory_item;
    line.taxes = [{ tax_code: '01', iva_rate_code: item.iva_rate_code ?? '08' }];

    if (line.is_service) {
        line.warehouse_id = '';
        line.warehouse_bin_id = '';
    }
}

function usesBins(warehouseId) {
    return props.warehouses.find((w) => w.id === warehouseId)?.uses_bins ?? false;
}

function binsOf(warehouseId) {
    return props.bins.filter((b) => b.warehouse_id === warehouseId);
}

function lineSubtotal(line) {
    return Number(line.quantity || 0) * Number(line.unit_price || 0) - Number(line.discount_amount || 0);
}

function lineTax(line) {
    return line.taxes.reduce((sum, tax) => {
        const rate = Number(props.catalogs.ivaRates[tax.iva_rate_code]?.percentage ?? 0);
        const gross = lineSubtotal(line) * rate / 100;
        const exonerated = gross * Number(tax.exonerated_percentage || 0) / 100;
        return sum + gross - exonerated;
    }, 0);
}

// --- Panel 4: configuración avanzada por línea ---

const advancedLine = ref(null);

function openAdvanced(index) {
    advancedLine.value = index;
}

function addTax(line) {
    line.taxes.push({ tax_code: '01', iva_rate_code: '08' });
}

// --- Panel 6: totales ---

const totals = computed(() => {
    let sale = 0, discounts = 0, tax = 0;
    let taxedServices = 0, taxedGoods = 0, exempt = 0, exonerated = 0, noSubject = 0;

    for (const line of form.lines) {
        const gross = Number(line.quantity || 0) * Number(line.unit_price || 0);
        const subtotal = gross - Number(line.discount_amount || 0);

        sale += gross;
        discounts += Number(line.discount_amount || 0);
        tax += lineTax(line);

        const hasExoneration = line.taxes.some((t) => Number(t.exonerated_percentage || 0) > 0);
        const codes = line.taxes.map((t) => t.iva_rate_code).filter(Boolean);

        if (hasExoneration) exonerated += subtotal;
        else if (codes.includes('10')) exempt += subtotal;
        else if (codes.length === 0 || codes.every((c) => c === '11')) noSubject += subtotal;
        else if (line.is_service) taxedServices += subtotal;
        else taxedGoods += subtotal;
    }

    const net = sale - discounts;

    return { sale, discounts, net, tax, total: net + tax, taxedServices, taxedGoods, exempt, exonerated, noSubject };
});

const paymentsTotal = computed(() =>
    form.payments.reduce((sum, p) => sum + Number(p.amount || 0), 0)
);

const paymentsMatch = computed(() =>
    Math.abs(paymentsTotal.value - totals.value.total) < 0.005
);

function addPayment() {
    if (form.payments.length >= props.catalogs.maxPaymentMethods) return;

    const remaining = totals.value.total - paymentsTotal.value;
    form.payments.push({ method_code: '01', amount: remaining > 0 ? remaining.toFixed(2) : 0 });
}

const referenceRequired = computed(() =>
    props.catalogs.requireReference.includes(form.fiscal_document_type)
);

watch(referenceRequired, (required) => {
    if (required && form.references.length === 0) {
        form.references.push({ document_type: '01', number: '', reason_code: '01', reason: '' });
    }
});

const canSubmit = computed(() => {
    if (! form.document_type_id || ! form.emitter_activity_code) return false;
    if (receiverRequired.value && ! form.business_partner_id) return false;
    if (isCredit.value && ! form.credit_term_days) return false;
    if (! isCredit.value && ! paymentsMatch.value) return false;
    if (referenceRequired.value && form.references.length === 0) return false;

    return form.lines.every((l) => l.cabys_code && l.description.length >= 3 && Number(l.quantity) > 0);
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            business_partner_id: data.business_partner_id === '' ? null : data.business_partner_id,
            credit_term_days: data.credit_term_days === '' ? null : data.credit_term_days,
            receiver_activity_code: data.receiver_activity_code === '' ? null : data.receiver_activity_code,
            notes: data.notes === '' ? null : data.notes,
            lines: data.lines.map((line) => ({
                ...line,
                item_id: line.item_id === '' ? null : line.item_id,
                warehouse_id: line.warehouse_id === '' ? null : line.warehouse_id,
                warehouse_bin_id: line.warehouse_bin_id === '' ? null : line.warehouse_bin_id,
                discount_code: line.discount_code === '' ? null : line.discount_code,
                discount_reason: line.discount_reason === '' ? null : line.discount_reason,
                vin_or_serial: line.vin_or_serial === '' ? null : line.vin_or_serial,
            })),
        }))
        .post(route('sales-documents.store'));
}
</script>

<template>
    <Head title="Nueva factura electrónica" />

    <AppLayout title="Nueva factura electrónica">
        <div v-if="page.props.errors?.billing" class="flash flash-error">{{ page.props.errors.billing }}</div>

        <p v-if="!hacienda.signer_configured" class="flash flash-warning">
            No hay certificado de firma configurado. El comprobante se va a emitir y registrar en el ERP, y su XML
            queda disponible para descargar, pero <strong>no se puede firmar ni enviar a Hacienda</strong> hasta cargar
            la llave criptográfica.
        </p>

        <form @submit.prevent="submit">
            <!-- PANEL 1 -->
            <section class="card panel">
                <h2>1 · Encabezado y partes</h2>

                <div class="grid-4">
                    <div class="field">
                        <label>Tipo de comprobante</label>
                        <select v-model="form.fiscal_document_type">
                            <option v-for="(label, code) in catalogs.documentTypes" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Tipo de documento (ERP)</label>
                        <select v-model="form.document_type_id" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                        <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                    </div>
                    <div class="field">
                        <label>Sucursal</label>
                        <input v-model="form.branch" type="text" maxlength="3" required>
                    </div>
                    <div class="field">
                        <label>Terminal</label>
                        <input v-model="form.terminal" type="text" maxlength="5" required>
                    </div>
                </div>

                <div class="grid-4">
                    <div class="field">
                        <label>Actividad económica del emisor</label>
                        <select v-model="form.emitter_activity_code" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="a in activities" :key="a.id" :value="a.code">{{ a.code }} — {{ a.name }}</option>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label>Cliente {{ receiverRequired ? '' : '(opcional en tiquete)' }}</label>
                        <select v-model="form.business_partner_id" :required="receiverRequired">
                            <option value="">— Consumidor final —</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">
                                {{ c.code }} — {{ c.name }}{{ c.tax_id ? ` (${c.tax_id})` : '' }}
                            </option>
                        </select>
                        <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                    </div>
                    <div class="field">
                        <label>Actividad del receptor</label>
                        <input v-model="form.receiver_activity_code" type="text" maxlength="6" placeholder="6 dígitos">
                    </div>
                </div>

                <p v-if="selectedCustomer" class="muted small">
                    Identificación: {{ catalogs.identificationTypes[selectedCustomer.identification_type] ?? '—' }} ·
                    {{ selectedCustomer.tax_id ?? 'sin cédula' }} ·
                    {{ selectedCustomer.email ?? 'sin correo' }}
                </p>
            </section>

            <!-- PANEL 2 -->
            <section class="card panel">
                <h2>2 · Términos financieros y comerciales</h2>

                <div class="grid-4">
                    <div class="field">
                        <label>Moneda</label>
                        <select v-model="form.currency_id" required>
                            <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Tipo de cambio</label>
                        <input v-model="form.exchange_rate" type="number" step="0.00001" min="0.00001" :disabled="!isForeignCurrency" required>
                    </div>
                    <div class="field">
                        <label>Condición de venta</label>
                        <select v-model="form.sale_condition">
                            <option v-for="(label, code) in catalogs.saleConditions" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Plazo del crédito (días)</label>
                        <input v-model="form.credit_term_days" type="number" min="1" :disabled="!isCredit" :required="isCredit">
                        <span v-if="form.errors.credit_term_days" class="error">{{ form.errors.credit_term_days }}</span>
                    </div>
                </div>

                <div class="grid-4">
                    <div class="field">
                        <label>Fecha de emisión</label>
                        <input v-model="form.document_date" type="date" required>
                    </div>
                    <div class="field">
                        <label>Fecha de contabilización</label>
                        <input v-model="form.posting_date" type="date" required>
                    </div>
                    <div class="field span-2">
                        <label>Observaciones</label>
                        <input v-model="form.notes" type="text" maxlength="255">
                    </div>
                </div>
            </section>

            <!-- PANEL 3 -->
            <section class="card panel">
                <h2>3 · Detalle de productos y servicios</h2>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Artículo</th>
                                <th>CAByS</th>
                                <th>Descripción</th>
                                <th>Bodega</th>
                                <th v-if="warehouses.some((w) => w.uses_bins)">Ubic.</th>
                                <th class="right">Cant.</th>
                                <th>U/M</th>
                                <th class="right">Precio</th>
                                <th>IVA</th>
                                <th class="right">Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.lines" :key="index">
                                <td class="num">{{ index + 1 }}</td>
                                <td>
                                    <select v-model="line.item_id" @change="onItemSelected(line)">
                                        <option value="">— Libre —</option>
                                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }}</option>
                                    </select>
                                </td>
                                <td><input v-model="line.cabys_code" type="text" maxlength="13" required class="cabys"></td>
                                <td><input v-model="line.description" type="text" maxlength="200" required></td>
                                <td>
                                    <select v-model="line.warehouse_id" :disabled="line.is_service" @change="line.warehouse_bin_id = ''">
                                        <option value="">—</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                    </select>
                                </td>
                                <td v-if="warehouses.some((w) => w.uses_bins)">
                                    <select v-if="usesBins(line.warehouse_id)" v-model="line.warehouse_bin_id" required>
                                        <option value="" disabled>—</option>
                                        <option v-for="b in binsOf(line.warehouse_id)" :key="b.id" :value="b.id">{{ b.code }}</option>
                                    </select>
                                    <span v-else class="muted small">—</span>
                                </td>
                                <td><input v-model="line.quantity" type="number" step="0.001" min="0.001" required class="right qty"></td>
                                <td>
                                    <select v-model="line.unit_code">
                                        <option v-for="(label, code) in catalogs.units" :key="code" :value="code">{{ code }}</option>
                                    </select>
                                </td>
                                <td><input v-model="line.unit_price" type="number" step="0.00001" min="0" required class="right"></td>
                                <td>
                                    <select v-model="line.taxes[0].iva_rate_code">
                                        <option v-for="(rate, code) in catalogs.ivaRates" :key="code" :value="code">
                                            {{ code }} ({{ rate.percentage }}%)
                                        </option>
                                    </select>
                                </td>
                                <td class="num right">{{ money(lineSubtotal(line)) }}</td>
                                <td class="actions-cell">
                                    <button type="button" class="btn btn-ghost" title="Descuentos, exoneración, VIN" @click="openAdvanced(index)">⚙</button>
                                    <button type="button" class="btn btn-ghost" :disabled="form.lines.length === 1" @click="form.lines.splice(index, 1)">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <button type="button" class="btn btn-ghost" @click="form.lines.push(blankLine())">+ Agregar línea</button>
            </section>

            <!-- PANEL 5 -->
            <section v-if="referenceRequired || form.references.length" class="card panel">
                <h2>5 · Información de referencia</h2>

                <p v-if="referenceRequired" class="hint">
                    Un comprobante del tipo <strong>{{ catalogs.documentTypes[form.fiscal_document_type] }}</strong>
                    exige indicar qué documento corrige o anula.
                </p>

                <div v-for="(reference, index) in form.references" :key="index" class="grid-4">
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="reference.document_type">
                            <option v-for="(label, code) in catalogs.referenceDocumentTypes" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Clave o número</label>
                        <input v-model="reference.number" type="text" maxlength="50" required>
                    </div>
                    <div class="field">
                        <label>Razón</label>
                        <select v-model="reference.reason_code">
                            <option v-for="(label, code) in catalogs.referenceReasons" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Justificación</label>
                        <input v-model="reference.reason" type="text" minlength="3" maxlength="180" required>
                    </div>
                </div>

                <button type="button" class="btn btn-ghost" @click="form.references.push({ document_type: '01', number: '', reason_code: '01', reason: '' })">
                    + Referencia
                </button>
            </section>

            <!-- PANEL 6 -->
            <section class="card panel">
                <h2>6 · Totales, medios de pago y emisión</h2>

                <div class="totals-grid">
                    <div class="totals-column">
                        <div><span>Subtotal ventas</span><strong class="num">{{ money(totals.sale) }}</strong></div>
                        <div><span>Total descuentos</span><strong class="num">{{ money(totals.discounts) }}</strong></div>
                        <div><span>Total venta neta</span><strong class="num">{{ money(totals.net) }}</strong></div>
                        <div><span>Total IVA cobrado</span><strong class="num">{{ money(totals.tax) }}</strong></div>
                    </div>
                    <div class="totals-column">
                        <div><span>Servicios gravados</span><strong class="num">{{ money(totals.taxedServices) }}</strong></div>
                        <div><span>Mercancías gravadas</span><strong class="num">{{ money(totals.taxedGoods) }}</strong></div>
                        <div><span>Total exento</span><strong class="num">{{ money(totals.exempt) }}</strong></div>
                        <div><span>Total exonerado</span><strong class="num">{{ money(totals.exonerated) }}</strong></div>
                        <div><span>Total no sujeto</span><strong class="num">{{ money(totals.noSubject) }}</strong></div>
                    </div>
                    <div class="totals-column grand">
                        <span>TOTAL COMPROBANTE</span>
                        <strong class="num">{{ money(totals.total) }}</strong>
                    </div>
                </div>

                <h3>Medios de pago</h3>

                <p v-if="isCredit" class="hint">
                    Una venta a crédito no se cobra en el acto: abre partida pendiente en Cuentas por Cobrar con
                    vencimiento a {{ form.credit_term_days || '—' }} días.
                </p>

                <template v-else>
                    <div v-for="(payment, index) in form.payments" :key="index" class="payment-row">
                        <select v-model="payment.method_code">
                            <option v-for="(label, code) in catalogs.paymentMethods" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                        <input v-model="payment.amount" type="number" step="0.01" min="0" class="right">
                        <button type="button" class="btn btn-ghost" @click="form.payments.splice(index, 1)">Quitar</button>
                    </div>

                    <div class="payment-actions">
                        <button
                            type="button" class="btn btn-ghost"
                            :disabled="form.payments.length >= catalogs.maxPaymentMethods"
                            @click="addPayment"
                        >
                            + Medio de pago
                        </button>
                        <span :class="paymentsMatch ? 'muted small' : 'mismatch'">
                            Suman {{ money(paymentsTotal) }} de {{ money(totals.total) }}
                        </span>
                    </div>
                </template>

                <div class="emit">
                    <button type="submit" class="btn btn-primary btn-emit" :disabled="form.processing || !canSubmit">
                        Emitir y registrar en el ERP
                    </button>
                </div>
            </section>
        </form>

        <!-- PANEL 4: sub-panel flotante por línea -->
        <div v-if="advancedLine !== null" class="modal-backdrop" @click.self="advancedLine = null">
            <div class="modal-card card">
                <h2>4 · Configuración avanzada — línea {{ advancedLine + 1 }}</h2>

                <h3>Descuento</h3>
                <div class="grid-2">
                    <div class="field">
                        <label>Código</label>
                        <select v-model="form.lines[advancedLine].discount_code">
                            <option value="">— Sin descuento —</option>
                            <option v-for="(label, code) in catalogs.discountCodes" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Monto</label>
                        <input v-model="form.lines[advancedLine].discount_amount" type="number" step="0.00001" min="0">
                    </div>
                </div>

                <div v-if="form.lines[advancedLine].discount_code === '99'" class="field">
                    <label>Naturaleza del descuento</label>
                    <input v-model="form.lines[advancedLine].discount_reason" type="text" maxlength="80">
                </div>

                <h3>Impuestos y exoneración</h3>

                <div v-for="(tax, taxIndex) in form.lines[advancedLine].taxes" :key="taxIndex" class="tax-block">
                    <div class="grid-2">
                        <div class="field">
                            <label>Tipo de impuesto</label>
                            <select v-model="tax.tax_code">
                                <option v-for="(label, code) in catalogs.taxCodes" :key="code" :value="code">
                                    {{ code }} — {{ label }}
                                </option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Tarifa de IVA</label>
                            <select v-model="tax.iva_rate_code">
                                <option v-for="(rate, code) in catalogs.ivaRates" :key="code" :value="code">
                                    {{ code }} — {{ rate.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <label>Tipo de documento de exoneración</label>
                            <select v-model="tax.exoneration_document_type">
                                <option value="">— Sin exoneración —</option>
                                <option v-for="(label, code) in catalogs.exonerationDocumentTypes" :key="code" :value="code">
                                    {{ code }} — {{ label }}
                                </option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Número de documento</label>
                            <input v-model="tax.exoneration_document_number" type="text" maxlength="40" :disabled="!tax.exoneration_document_type">
                        </div>
                    </div>

                    <div v-if="tax.exoneration_document_type" class="grid-4">
                        <div class="field">
                            <label>Artículo</label>
                            <input v-model="tax.exoneration_article" type="text" maxlength="10">
                        </div>
                        <div class="field">
                            <label>Inciso</label>
                            <input v-model="tax.exoneration_clause" type="text" maxlength="10">
                        </div>
                        <div class="field">
                            <label>Institución</label>
                            <select v-model="tax.exoneration_institution">
                                <option value="">—</option>
                                <option v-for="(label, code) in catalogs.exonerationInstitutions" :key="code" :value="code">
                                    {{ code }} — {{ label }}
                                </option>
                            </select>
                        </div>
                        <div class="field">
                            <label>% exonerado</label>
                            <input v-model="tax.exonerated_percentage" type="number" step="0.01" min="0" max="100">
                        </div>
                    </div>
                </div>

                <button type="button" class="btn btn-ghost" @click="addTax(form.lines[advancedLine])">+ Impuesto</button>

                <h3>Trazabilidad</h3>
                <div class="field">
                    <label>Número de VIN o serie (vehículos, aeronaves, embarcaciones)</label>
                    <input v-model="form.lines[advancedLine].vin_or_serial" type="text" maxlength="17">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-primary" @click="advancedLine = null">Listo</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.panel { padding: 1.1rem 1.25rem; margin-bottom: 0.9rem; }
.panel h2 { font-size: 0.92rem; margin: 0 0 0.9rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--color-border); }
.panel h3 { font-size: 0.82rem; margin: 1rem 0 0.5rem; color: var(--color-text-muted); }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: var(--color-warning-soft); color: var(--color-warning); }

.hint { font-size: 0.8rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }

.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0 1rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
.span-2 { grid-column: span 2; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.7rem; }
.field label { font-size: 0.76rem; color: var(--color-text-muted); }

.field input, .field select, td input, td select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.42rem 0.55rem;
    font-size: 0.84rem;
    color: var(--color-text);
}

.field input:disabled, td input:disabled, td select:disabled { opacity: 0.45; }

.table-scroll { overflow-x: auto; margin-bottom: 0.6rem; }
table { font-size: 0.82rem; width: 100%; }
th, td { text-align: left; padding: 0.35rem 0.4rem; border-top: 1px solid var(--color-border); }
th.right, td.right, td input.right { text-align: right; }
td .cabys { font-variant-numeric: tabular-nums; }
td .qty { max-width: 90px; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.actions-cell { display: flex; gap: 0.2rem; }

.totals-grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 1.5rem; align-items: start; }
.totals-column > div { display: flex; justify-content: space-between; gap: 1.5rem; font-size: 0.83rem; padding: 0.15rem 0; }
.totals-column span { color: var(--color-text-muted); }
.totals-column.grand {
    display: flex; flex-direction: column; align-items: flex-end; justify-content: center;
    padding: 0.75rem 1rem; background: var(--color-surface-alt); border-radius: var(--radius-sm);
}
.totals-column.grand span { font-size: 0.74rem; letter-spacing: 0.04em; }
.totals-column.grand strong { font-size: 1.3rem; }

.payment-row { display: grid; grid-template-columns: 2fr 1fr auto; gap: 0.6rem; margin-bottom: 0.5rem; }
.payment-row select, .payment-row input {
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: var(--radius-sm); padding: 0.42rem 0.55rem; font-size: 0.84rem; color: var(--color-text);
}
.payment-actions { display: flex; align-items: center; gap: 1rem; }
.mismatch { color: var(--color-danger); font-size: 0.8rem; font-weight: 600; }

.emit { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--color-border); }
.btn-emit { width: 100%; padding: 0.8rem; font-size: 0.92rem; }

.error { color: var(--color-danger); font-size: 0.75rem; }

.modal-backdrop {
    position: fixed; inset: 0; background: rgba(11, 31, 58, 0.45);
    display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1rem;
}

.modal-card { width: 720px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.75rem; }
.tax-block { border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 0.75rem; margin-bottom: 0.6rem; }
.modal-actions { display: flex; gap: 0.6rem; margin-top: 1rem; }
</style>
