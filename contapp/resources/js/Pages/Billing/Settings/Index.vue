<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { PlusIcon } from '@lucide/vue';

const props = defineProps({
    activities: { type: Array, default: () => [] },
    taxAccounts: { type: Array, default: () => [] },
    paymentAccounts: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    taxRates: { type: Array, default: () => [] },
    catalogs: { type: Object, required: true },
    hacienda: { type: Object, default: () => ({}) },
});

const page = usePage();

const activityForm = useForm({ code: '', name: '', revenue_account_id: '', is_default: false });
const taxForm = useForm({ iva_rate_code: '08', account_id: '', tax_rate_id: '' });
const paymentForm = useForm({ method_code: '01', account_id: '' });

// Cada sección agrega en un modal que abre su botón «Agregar», arriba de la
// tabla (CLAUDE.md secc. 21): 'activity' | 'tax' | 'payment'.
const adding = ref(null);

const addTitles = {
    activity: 'Nueva actividad económica',
    tax: 'Nueva cuenta de IVA débito fiscal',
    payment: 'Nueva cuenta por medio de pago',
};

const addForms = { activity: activityForm, tax: taxForm, payment: paymentForm };
const addProcessing = computed(() => (adding.value ? addForms[adding.value].processing : false));

function openAdd(kind) {
    addForms[kind].reset();
    addForms[kind].clearErrors();
    adding.value = kind;
}

const closeAdd = () => { adding.value = null; };

function submitActivity() {
    activityForm.post(route('billing-settings.activities.store'), {
        onSuccess: () => { activityForm.reset(); closeAdd(); }, preserveScroll: true,
    });
}

function submitTax() {
    taxForm
        .transform((data) => ({ ...data, tax_rate_id: data.tax_rate_id === '' ? null : data.tax_rate_id }))
        .post(route('billing-settings.tax-accounts.store'), {
            onSuccess: () => { taxForm.reset(); closeAdd(); }, preserveScroll: true,
        });
}

function submitPayment() {
    paymentForm.post(route('billing-settings.payment-accounts.store'), {
        onSuccess: () => { paymentForm.reset(); closeAdd(); }, preserveScroll: true,
    });
}

// Ficha de una fila (CLAUDE.md secc. 20): el detalle y «Eliminar».
const selected = ref(null); // { kind, id }

const sources = {
    activity: () => props.activities,
    tax: () => props.taxAccounts,
    payment: () => props.paymentAccounts,
};

const selectedRecord = computed(() => {
    if (! selected.value) return null;
    return sources[selected.value.kind]().find((r) => r.id === selected.value.id) ?? null;
});

function openDetail(kind, record) {
    selected.value = { kind, id: record.id };
}

const closeDetail = () => { selected.value = null; };

const detailTitle = computed(() => {
    const r = selectedRecord.value;
    if (! r) return '';

    switch (selected.value.kind) {
        case 'activity': return `${r.code} — ${r.name}`;
        case 'tax': return `IVA ${r.iva_rate_code} — ${props.catalogs.ivaRates[r.iva_rate_code]?.label ?? ''}`;
        default: return `${r.method_code} — ${props.catalogs.paymentMethods[r.method_code] ?? ''}`;
    }
});

const destroyRoutes = {
    activity: 'billing-settings.activities.destroy',
    tax: 'billing-settings.tax-accounts.destroy',
    payment: 'billing-settings.payment-accounts.destroy',
};

function destroySelected() {
    const { kind } = selected.value;
    const r = selectedRecord.value;

    confirmAction({
        title: 'Eliminar configuración',
        message: kind === 'activity'
            ? `La actividad ${r.code} se elimina de la configuración de facturación.`
            : `«${detailTitle.value}» se elimina de la configuración de facturación.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route(destroyRoutes[kind], r.id), { preserveScroll: true }),
    });
}

function accountLabel(account) {
    return account ? `${account.code} — ${account.description_es}` : '—';
}
</script>

<template>
    <Head title="Configuración de facturación" />

    <AppLayout title="Configuración de facturación">
        <div v-for="(message, key) in page.props.errors" :key="key" class="flash flash-error">{{ message }}</div>

        <div class="card hacienda" :class="hacienda.signer_configured ? '' : 'pending'">
            <div>
                <strong>Firma electrónica</strong>
                <p class="muted small">
                    {{ hacienda.signer_configured
                        ? 'Certificado cargado: los comprobantes se pueden firmar.'
                        : 'Sin certificado. Se pueden emitir comprobantes y registrarlos en el ERP, y descargar su XML, pero no firmarlos.' }}
                </p>
            </div>
            <div>
                <strong>Envío a la DGT</strong>
                <p class="muted small">
                    {{ hacienda.transport_configured
                        ? 'Credenciales del ATV cargadas.'
                        : 'Sin credenciales del ATV. Los comprobantes no se envían a Hacienda todavía.' }}
                </p>
            </div>
        </div>

        <!-- Actividades económicas -->
        <section class="card panel">
            <div class="panel-head">
                <div>
                    <h2>Actividades económicas del emisor</h2>
                    <p class="hint">De cuál se factura define a qué cuenta de ingresos va la venta.</p>
                </div>
                <button type="button" class="btn btn-primary" @click="openAdd('activity')"><PlusIcon /> Agregar</button>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Código</th><th>Nombre</th><th>Cuenta de ingresos</th><th>Por defecto</th></tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in activities"
                            :key="a.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail('activity', a)"
                            @keydown.enter="openDetail('activity', a)"
                            @keydown.space.prevent="openDetail('activity', a)"
                        >
                            <td class="code-cell">{{ a.code }}</td>
                            <td data-label="Nombre">{{ a.name }}</td>
                            <td data-label="Cuenta de ingresos" class="muted small">{{ accountLabel(a.revenue_account) }}</td>
                            <td data-label="Por defecto"><span v-if="a.is_default" class="badge badge-success">Sí</span></td>
                        </tr>
                        <tr v-if="!activities.length"><td colspan="4" class="muted empty-row">Sin actividades registradas.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- IVA débito fiscal -->
        <section class="card panel">
            <div class="panel-head">
                <div>
                    <h2>Cuentas de IVA débito fiscal</h2>
                    <p class="hint">
                        Contra qué cuenta se acredita el impuesto de cada tarifa. El indicador de impuesto es opcional pero
                        recomendado: es lo que hace que estas ventas aparezcan en el reporte de IVA existente.
                    </p>
                </div>
                <button type="button" class="btn btn-primary" @click="openAdd('tax')"><PlusIcon /> Agregar</button>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Tarifa</th><th>Cuenta</th><th>Indicador de impuesto</th></tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="t in taxAccounts"
                            :key="t.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail('tax', t)"
                            @keydown.enter="openDetail('tax', t)"
                            @keydown.space.prevent="openDetail('tax', t)"
                        >
                            <td>{{ t.iva_rate_code }} — {{ catalogs.ivaRates[t.iva_rate_code]?.label }}</td>
                            <td data-label="Cuenta" class="muted small">{{ accountLabel(t.account) }}</td>
                            <td data-label="Indicador de impuesto" class="muted small">{{ t.tax_rate ? `${t.tax_rate.code} (${t.tax_rate.percentage}%)` : '—' }}</td>
                        </tr>
                        <tr v-if="!taxAccounts.length"><td colspan="3" class="muted empty-row">Sin tarifas configuradas.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Medios de pago -->
        <section class="card panel">
            <div class="panel-head">
                <div>
                    <h2>Cuentas por medio de pago</h2>
                    <p class="hint">Contra qué cuenta se debita una venta de contado según cómo se cobró.</p>
                </div>
                <button type="button" class="btn btn-primary" @click="openAdd('payment')"><PlusIcon /> Agregar</button>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Medio de pago</th><th>Cuenta</th></tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in paymentAccounts"
                            :key="p.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail('payment', p)"
                            @keydown.enter="openDetail('payment', p)"
                            @keydown.space.prevent="openDetail('payment', p)"
                        >
                            <td>{{ p.method_code }} — {{ catalogs.paymentMethods[p.method_code] }}</td>
                            <td data-label="Cuenta" class="muted small">{{ accountLabel(p.account) }}</td>
                        </tr>
                        <tr v-if="!paymentAccounts.length"><td colspan="2" class="muted empty-row">Sin medios de pago configurados.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Ficha de una fila -->
        <DetailModal :open="!!selectedRecord" :title="detailTitle" @close="closeDetail">
            <dl v-if="selectedRecord" class="detail-list">
                <template v-if="selected.kind === 'activity'">
                    <div>
                        <dt>Cuenta de ingresos</dt>
                        <dd>{{ accountLabel(selectedRecord.revenue_account) }}</dd>
                    </div>
                    <div>
                        <dt>Por defecto</dt>
                        <dd>{{ selectedRecord.is_default ? 'Sí' : 'No' }}</dd>
                    </div>
                </template>
                <template v-else-if="selected.kind === 'tax'">
                    <div>
                        <dt>Cuenta</dt>
                        <dd>{{ accountLabel(selectedRecord.account) }}</dd>
                    </div>
                    <div>
                        <dt>Indicador de impuesto</dt>
                        <dd>{{ selectedRecord.tax_rate ? `${selectedRecord.tax_rate.code} (${selectedRecord.tax_rate.percentage}%)` : '—' }}</dd>
                    </div>
                </template>
                <div v-else>
                    <dt>Cuenta</dt>
                    <dd>{{ accountLabel(selectedRecord.account) }}</dd>
                </div>
            </dl>

            <template #actions>
                <button v-if="selectedRecord" type="button" class="btn btn-ghost btn-danger-text" @click="destroySelected">Eliminar</button>
            </template>
        </DetailModal>

        <!-- Agregar -->
        <DetailModal :open="!!adding" :title="adding ? addTitles[adding] : ''" @close="closeAdd">
            <form v-if="adding === 'activity'" id="billing-add-form" @submit.prevent="submitActivity">
                <div class="field-row">
                    <div class="field">
                        <label for="activity-code">Código (6 dígitos)</label>
                        <input id="activity-code" v-model="activityForm.code" type="text" maxlength="6" required>
                        <span v-if="activityForm.errors.code" class="error">{{ activityForm.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="activity-name">Nombre de la actividad</label>
                        <input id="activity-name" v-model="activityForm.name" type="text" required>
                        <span v-if="activityForm.errors.name" class="error">{{ activityForm.errors.name }}</span>
                    </div>
                </div>
                <div class="field">
                    <label for="activity-account">Cuenta de ingresos</label>
                    <select id="activity-account" v-model="activityForm.revenue_account_id" required>
                        <option value="" disabled>— Cuenta de ingresos —</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="activityForm.errors.revenue_account_id" class="error">{{ activityForm.errors.revenue_account_id }}</span>
                </div>
                <label class="check"><input v-model="activityForm.is_default" type="checkbox"> Actividad por defecto</label>
            </form>

            <form v-else-if="adding === 'tax'" id="billing-add-form" @submit.prevent="submitTax">
                <div class="field">
                    <label for="tax-rate-code">Tarifa</label>
                    <select id="tax-rate-code" v-model="taxForm.iva_rate_code" required>
                        <option v-for="(rate, code) in catalogs.ivaRates" :key="code" :value="code">{{ code }} — {{ rate.label }}</option>
                    </select>
                    <span v-if="taxForm.errors.iva_rate_code" class="error">{{ taxForm.errors.iva_rate_code }}</span>
                </div>
                <div class="field">
                    <label for="tax-account">Cuenta de IVA por pagar</label>
                    <select id="tax-account" v-model="taxForm.account_id" required>
                        <option value="" disabled>— Cuenta de IVA por pagar —</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="taxForm.errors.account_id" class="error">{{ taxForm.errors.account_id }}</span>
                </div>
                <div class="field">
                    <label for="tax-indicator">Indicador de impuesto (opcional)</label>
                    <select id="tax-indicator" v-model="taxForm.tax_rate_id">
                        <option value="">— Sin indicador —</option>
                        <option v-for="r in taxRates" :key="r.id" :value="r.id">{{ r.code }} — {{ r.percentage }}%</option>
                    </select>
                </div>
            </form>

            <form v-else-if="adding === 'payment'" id="billing-add-form" @submit.prevent="submitPayment">
                <div class="field">
                    <label for="payment-method">Medio de pago</label>
                    <select id="payment-method" v-model="paymentForm.method_code" required>
                        <option v-for="(label, code) in catalogs.paymentMethods" :key="code" :value="code">{{ code }} — {{ label }}</option>
                    </select>
                    <span v-if="paymentForm.errors.method_code" class="error">{{ paymentForm.errors.method_code }}</span>
                </div>
                <div class="field">
                    <label for="payment-account">Cuenta</label>
                    <select id="payment-account" v-model="paymentForm.account_id" required>
                        <option value="" disabled>— Cuenta —</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="paymentForm.errors.account_id" class="error">{{ paymentForm.errors.account_id }}</span>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="closeAdd">Cancelar</button>
                <button v-if="adding" type="submit" form="billing-add-form" class="btn btn-primary" :disabled="addProcessing">Agregar</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.panel { padding: 1.1rem 1.25rem; margin-bottom: 0.9rem; }
.panel h2 { font-size: 0.92rem; margin: 0 0 0.35rem; }
.panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem 1rem; flex-wrap: wrap; }
.panel-head > div { flex: 1 1 20rem; min-width: 0; }

.hacienda { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem 1.5rem; padding: 1rem 1.25rem; margin-bottom: 0.9rem; }
.hacienda.pending { border-left: 3px solid var(--color-warning); }
.hacienda p { margin: 0.2rem 0 0; }

table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; }

@media (max-width: 640px) {
    .panel-head .btn { width: 100%; justify-content: center; }
}
</style>
