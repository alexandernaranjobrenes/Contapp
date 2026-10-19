<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
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
    companyTaxId: { type: String, default: null },
    // Las actividades de TRIBU-CR (CIIU 4), código => nombre (EconomicActivityCatalog).
    activityCatalog: { type: Object, default: () => ({}) },
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
    // La primera actividad de la compañía es la de por defecto.
    if (kind === 'activity') activityForm.is_default = props.activities.length === 0;
    adding.value = kind;
}

const closeAdd = () => { adding.value = null; };

// --- Las actividades que Hacienda tiene inscritas a la cédula ---
// (api.hacienda.go.cr/fe/ae, por HaciendaLookupController). Facturar con una
// que no está inscrita es un rechazo seguro: -408.
const registered = ref(null); // null = sin consultar; { available, activities }
const loadingRegistered = ref(false);

async function loadRegistered() {
    if (! props.companyTaxId) return;
    loadingRegistered.value = true;

    try {
        const res = await fetch(route('hacienda.taxpayer', { identification: props.companyTaxId }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.redirected || ! res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        registered.value = {
            available: data.available,
            found: data.taxpayer?.found ?? false,
            activities: (data.taxpayer?.activities ?? []).filter((a) => a.active),
        };
    } catch {
        registered.value = { available: false, found: false, activities: [] };
    } finally {
        loadingRegistered.value = false;
    }
}

onMounted(loadRegistered);

const registeredKnown = computed(() => registered.value?.available && registered.value.found);

function registrationOf(activity) {
    if (! registeredKnown.value) return null;
    return registered.value.activities.some((a) => a.code === activity.code);
}

// Las inscritas que la compañía todavía no configuró.
const notConfigured = computed(() => (registeredKnown.value
    ? registered.value.activities.filter((a) => ! props.activities.some((c) => c.code === a.code))
    : []));

// En «Nueva actividad económica», código y nombre son dos listas con todas
// las actividades de Hacienda que la compañía todavía no configuró: elegir en
// una llena la otra. Arriba, las inscritas a la cédula; abajo, el resto del
// catálogo de TRIBU-CR (con una de esas Hacienda rechaza el comprobante).
const choices = computed(() => {
    const configured = new Set(props.activities.map((a) => a.code));
    const inscribed = (registeredKnown.value ? registered.value.activities : [])
        .filter((a) => ! configured.has(a.code))
        .map((a) => ({ code: a.code, name: props.activityCatalog[a.code] ?? a.description }));
    const inscribedCodes = new Set(inscribed.map((a) => a.code));
    const others = Object.entries(props.activityCatalog)
        .filter(([code]) => ! configured.has(code) && ! inscribedCodes.has(code))
        .map(([code, name]) => ({ code, name }));

    return { inscribed, others };
});

const byName = (a, b) => a.name.localeCompare(b.name, 'es');
const choicesByName = computed(() => ({
    inscribed: [...choices.value.inscribed].sort(byName),
    others: [...choices.value.others].sort(byName),
}));

const othersLabel = computed(() => (registeredKnown.value
    ? 'Otras del catálogo de Hacienda (no inscritas a la cédula)'
    : 'Catálogo de actividades de Hacienda'));

function chooseActivity(code) {
    const activity = [...choices.value.inscribed, ...choices.value.others].find((a) => a.code === code);
    activityForm.code = activity?.code ?? '';
    activityForm.name = activity?.name ?? '';
}

// Elegida una que Hacienda dice que la cédula no tiene inscrita.
const choiceNotInscribed = computed(() => registeredKnown.value && activityForm.code !== ''
    && ! registered.value.activities.some((a) => a.code === activityForm.code));

function addRegistered(activity) {
    openAdd('activity');
    chooseActivity(activity.code);
}

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

        <!-- Facturación electrónica: se configura en Administración, solo el
             Superusuario (CLAUDE.md secc. 38). Acá solo se informa. -->
        <div class="card hacienda" :class="hacienda.enabled && hacienda.ready ? '' : 'pending'">
            <div>
                <strong>Facturación electrónica</strong>
                <p class="muted small">
                    <template v-if="hacienda.enabled && hacienda.ready">Encendida ({{ hacienda.environment_label }}): cada comprobante se firma y se envía a Hacienda.</template>
                    <template v-else-if="hacienda.enabled">Encendida pero incompleta: no se puede emitir hasta completarla.</template>
                    <template v-else>Apagada: los comprobantes se registran en el ERP sin enviarse a Hacienda.</template>
                </p>
            </div>
            <div v-if="hacienda.missing?.length">
                <strong>Falta</strong>
                <ul class="muted small missing"><li v-for="item in hacienda.missing" :key="item">{{ item }}</li></ul>
            </div>
            <div>
                <p class="muted small">
                    Las credenciales del ATV y la llave criptográfica las carga el Superusuario en
                    <Link v-if="page.props.auth?.user?.is_super_admin" :href="route('hacienda-settings.index')" class="link">Administración → Facturación electrónica</Link>
                    <template v-else>Administración → Facturación electrónica</template>.
                    El código de actividad va con el formato de la v4.4 (6201.0).
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
                        <tr><th>Código</th><th>Nombre</th><th>Cuenta de ingresos</th><th>Por defecto</th><th>En Hacienda</th></tr>
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
                            <td data-label="En Hacienda">
                                <span v-if="registrationOf(a) === true" class="badge badge-success">Inscrita</span>
                                <span
                                    v-else-if="registrationOf(a) === false"
                                    class="badge badge-danger"
                                    title="Hacienda rechaza el comprobante con una actividad que el emisor no tiene inscrita (-408)."
                                >No inscrita</span>
                                <span v-else class="muted small">—</span>
                            </td>
                        </tr>
                        <tr v-if="!activities.length"><td colspan="5" class="muted empty-row">Sin actividades registradas.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="registered">
                <p v-if="!companyTaxId" class="muted small">La compañía no tiene cédula: no se puede consultar qué actividades tiene inscritas en Hacienda.</p>
                <p v-else-if="loadingRegistered" class="muted small">Consultando en Hacienda las actividades inscritas a la cédula {{ companyTaxId }}…</p>
                <p v-else-if="registered && !registered.available" class="muted small">
                    Hacienda no contestó la consulta de actividades inscritas.
                    <button type="button" class="btn btn-ghost" @click="loadRegistered">Reintentar</button>
                </p>
                <p v-else-if="registered && !registered.found" class="muted small">Hacienda no tiene inscrita la cédula {{ companyTaxId }}.</p>
                <template v-else-if="registered">
                    <p class="muted small">
                        Inscritas en Hacienda a la cédula {{ companyTaxId }}:
                        <template v-if="!registered.activities.length">ninguna activa.</template>
                        <template v-else-if="!notConfigured.length">todas están configuradas.</template>
                    </p>
                    <ul v-if="notConfigured.length" class="registered-list">
                        <li v-for="a in notConfigured" :key="a.code">
                            <span><strong class="code-cell">{{ a.code }}</strong> {{ a.description }}<template v-if="a.primary"> · principal</template></span>
                            <button type="button" class="btn btn-ghost" @click="addRegistered(a)"><PlusIcon /> Agregar</button>
                        </li>
                    </ul>
                </template>
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
                <div class="field-row activity-pick">
                    <div class="field">
                        <label for="activity-code">Código</label>
                        <select id="activity-code" :value="activityForm.code" required @change="chooseActivity($event.target.value)">
                            <option value="" disabled>— Elegir —</option>
                            <optgroup v-if="choices.inscribed.length" :label="`Inscritas a la cédula ${companyTaxId}`">
                                <option v-for="a in choices.inscribed" :key="a.code" :value="a.code">{{ a.code }}</option>
                            </optgroup>
                            <optgroup :label="othersLabel">
                                <option v-for="a in choices.others" :key="a.code" :value="a.code">{{ a.code }}</option>
                            </optgroup>
                        </select>
                        <span v-if="activityForm.errors.code" class="error">{{ activityForm.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="activity-name">Nombre de la actividad</label>
                        <select id="activity-name" :value="activityForm.code" required @change="chooseActivity($event.target.value)">
                            <option value="" disabled>— Elegir —</option>
                            <optgroup v-if="choicesByName.inscribed.length" :label="`Inscritas a la cédula ${companyTaxId}`">
                                <option v-for="a in choicesByName.inscribed" :key="a.code" :value="a.code">{{ a.name }}</option>
                            </optgroup>
                            <optgroup :label="othersLabel">
                                <option v-for="a in choicesByName.others" :key="a.code" :value="a.code">{{ a.name }}</option>
                            </optgroup>
                        </select>
                        <span v-if="activityForm.errors.name" class="error">{{ activityForm.errors.name }}</span>
                    </div>
                    <p v-if="choiceNotInscribed" class="hint span-full warn">
                        Hacienda no tiene esta actividad inscrita a la cédula {{ companyTaxId }}: un comprobante con ella se rechaza (-408).
                        Primero hay que inscribirla en TRIBU-CR.
                    </p>
                    <p v-else-if="loadingRegistered" class="hint span-full">Consultando en Hacienda las actividades inscritas a la cédula…</p>
                    <p v-else-if="!registeredKnown" class="hint span-full">
                        No se pudo saber qué actividades tiene inscritas la cédula: Hacienda no contestó o la compañía no tiene cédula.
                    </p>
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
.hacienda .missing { margin: 0.2rem 0 0; padding-left: 1.1rem; }
.hacienda .link { color: var(--color-primary); }

table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; }

.registered { margin-top: 0.75rem; }
.field-row .span-full { flex-basis: 100%; margin: 0; font-size: 0.78rem; }
.activity-pick .field:first-child { flex: 0 0 8rem; min-width: 8rem; }
.activity-pick select { width: 100%; min-width: 0; text-overflow: ellipsis; }
.activity-pick .warn { color: var(--color-danger); }
.registered p { margin: 0; }
.registered-list { list-style: none; margin: 0.35rem 0 0; padding: 0; display: grid; gap: 0.25rem; font-size: 0.85rem; }
.registered-list li { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; }

@media (max-width: 640px) {
    .panel-head .btn { width: 100%; justify-content: center; }
}
</style>
