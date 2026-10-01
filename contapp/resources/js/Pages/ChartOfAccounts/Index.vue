<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { DownloadIcon, PencilIcon, PlusIcon, ScrollTextIcon, UploadIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import { confirmAction } from '../../Utils/confirm';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    accountTypes: { type: Object, required: true },
    taxRates: { type: Array, default: () => [] },
});

const taxRatesById = computed(() => Object.fromEntries(props.taxRates.map((r) => [r.id, r])));

const page = usePage();
const search = ref('');
const openDrawer = ref(null);

// --- mayor auxiliar (panel deslizante) ---

const ledger = ref({ open: false, ownerId: null, ownerLabel: '' });

function openLedger(account) {
    ledger.value = { open: true, ownerId: account.id, ownerLabel: `${account.code} — ${account.description_es}` };
}

function closeLedger() {
    ledger.value.open = false;
}

// --- carga masiva (plantilla XLSX) ---

const fileInput = ref(null);
const importForm = useForm({ file: null });
const importErrors = computed(() => page.props.flash?.importErrors ?? []);

function onFileSelected(e) {
    const file = e.target.files[0];
    if (! file) return;

    importForm.file = file;
    importForm.post(route('chart-of-accounts.import'), {
        preserveScroll: true,
        onFinish: () => {
            importForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

const grouped = computed(() => {
    const q = search.value.trim().toLowerCase();
    const byType = {};

    for (const type of Object.keys(props.accountTypes)) {
        byType[type] = props.accounts.filter((a) => {
            if (a.account_type !== type) return false;
            if (!q) return true;
            return a.code.toLowerCase().includes(q) || a.description_es.toLowerCase().includes(q);
        });
    }

    return byType;
});

function toggleDrawer(type) {
    openDrawer.value = openDrawer.value === type ? null : type;
}

const taxLabels = {
    none: '',
    sales: 'Ventas',
    purchases: 'Compras',
    iva_general: 'IVA General',
    iva_devengado: 'IVA Devengado',
    iva_soportado: 'IVA Soportado',
};

const currencyLabels = { local: 'Local', foreign: 'Extranjera', both: 'Ambas' };

// --- ficha de la cuenta (CLAUDE.md secc. 20) y alta (secc. 21) ---
//
// Un solo modal: la fila lo abre en 'details' (con «Ver movimientos», que
// abre el mayor auxiliar), «Crear nuevo» en 'create'. Crear y editar
// comparten el formulario.

const mode = ref('details'); // 'create' | 'details' | 'edit'
const selectedId = ref(null);

const selected = computed(() => props.accounts.find((a) => a.id === selectedId.value) ?? null);
const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva cuenta';
    return selected.value ? `${selected.value.code} — ${selected.value.description_es}` : '';
});

const DEFAULTS = {
    code: '',
    description_es: '',
    description_en: '',
    account_type: 'asset',
    currency_mode: 'local',
    tax_classification: 'none',
    tax_rate_id: null,
    accepts_posting: true,
    requires_business_partner: false,
    is_cash_account: false,
    requires_cost_center: false,
    is_active: true,
};

const form = useForm({ ...DEFAULTS });

function fillForm(values) {
    form.clearErrors();
    for (const [key, value] of Object.entries(values)) form[key] = value;
}

// La clase de la cuenta nueva es la del cajón abierto: es donde el usuario
// está mirando.
function openCreate() {
    fillForm({ ...DEFAULTS, account_type: openDrawer.value ?? Object.keys(props.accountTypes)[0] });
    selectedId.value = null;
    mode.value = 'create';
}

function openAccount(account) {
    selectedId.value = account.id;
    mode.value = 'details';
}

function closeAccount() {
    selectedId.value = null;
    mode.value = 'details';
}

function startEdit() {
    const account = selected.value;
    fillForm({
        code: account.code,
        description_es: account.description_es,
        description_en: account.description_en ?? '',
        account_type: account.account_type,
        currency_mode: account.currency_mode,
        tax_classification: account.tax_classification,
        tax_rate_id: account.tax_rate_id,
        accepts_posting: account.accepts_posting,
        requires_business_partner: account.requires_business_partner,
        is_cash_account: account.is_cash_account,
        requires_cost_center: account.requires_cost_center,
        is_active: account.is_active,
    });
    mode.value = 'edit';
}

function cancelForm() {
    if (mode.value === 'create') {
        closeAccount();
        return;
    }

    mode.value = 'details';
}

function submit() {
    if (mode.value === 'create') {
        form.post(route('chart-of-accounts.store'), { preserveScroll: true, onSuccess: closeAccount });
        return;
    }

    form.put(route('chart-of-accounts.update', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

function destroy() {
    const account = selected.value;

    confirmAction({
        title: 'Eliminar cuenta',
        message: `La cuenta ${account.code} — ${account.description_es} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('chart-of-accounts.destroy', account.id), { preserveScroll: true }),
    });
}

// El mayor se abre en lugar de la ficha: son dos paneles que no conviven.
function showMovements() {
    const account = selected.value;
    closeAccount();
    openLedger(account);
}

function canReconcile(account) {
    return account.accepts_posting && !account.requires_business_partner;
}

function taxRateLabel(account) {
    const rate = taxRatesById.value[account.tax_rate_id];
    return rate ? `${rate.code} (${rate.percentage}%)` : null;
}
</script>

<template>
    <Head title="Catálogo de cuentas" />

    <AppLayout title="Catálogo de cuentas">
        <div class="view-toolbar">
            <div class="view-actions">
                <a
                    :href="route('chart-of-accounts.template')"
                    class="btn btn-ghost"
                    title="Plantilla de Excel para crear o actualizar cuentas por lote"
                ><DownloadIcon /> Descargar plantilla</a>
                <label class="btn btn-ghost file-btn" :class="{ disabled: importForm.processing }" title="Subir la plantilla completa">
                    <UploadIcon /> {{ importForm.processing ? 'Subiendo...' : 'Importar XLSX' }}
                    <input ref="fileInput" type="file" accept=".xlsx" :disabled="importForm.processing" @change="onFileSelected">
                </label>
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar cuenta">
            </div>
        </div>

        <p v-if="importForm.errors.file" class="flash flash-error">{{ importForm.errors.file }}</p>

        <div v-if="importErrors.length" class="import-errors">
            <p>No se importó nada porque el archivo tiene {{ importErrors.length }} error(es). Corregilos y subilo de nuevo:</p>
            <ul>
                <li v-for="(msg, i) in importErrors" :key="i">{{ msg }}</li>
            </ul>
        </div>

        <div v-if="page.props.errors?.account" class="flash flash-error">{{ page.props.errors.account }}</div>

        <div class="cabinet">
            <div v-for="(label, type) in accountTypes" :key="type" class="drawer-unit">
                <button type="button" class="drawer" :class="{ open: openDrawer === type }" :aria-expanded="openDrawer === type" @click="toggleDrawer(type)">
                    <span class="drawer-handle"></span>
                    <span class="drawer-label">{{ label }}</span>
                    <span class="drawer-count">{{ grouped[type].length }}</span>
                </button>

                <div v-if="openDrawer === type" class="drawer-content card">
                    <div class="table-responsive table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Naturaleza</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="account in grouped[type]"
                                    :key="account.id"
                                    class="clickable-row"
                                    :class="{ 'is-major-row': !account.accepts_posting }"
                                    tabindex="0"
                                    @click="openAccount(account)"
                                    @keydown.enter="openAccount(account)"
                                    @keydown.space.prevent="openAccount(account)"
                                >
                                    <td class="code-cell">{{ account.code }}</td>
                                    <td data-label="Nombre" class="name-cell">{{ account.description_es }}</td>
                                    <td data-label="Naturaleza">{{ account.normal_balance === 'debit' ? 'Débito' : 'Crédito' }}</td>
                                    <td data-label="Estado">
                                        <span class="badge" :class="account.is_active ? 'badge-success' : 'badge-danger'">
                                            {{ account.is_active ? 'Activa' : 'Inactiva' }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!grouped[type].length">
                                    <td colspan="4" class="muted empty-row">No hay cuentas en esta clase todavía.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="closeAccount">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.is_active ? 'badge-success' : 'badge-danger'">
                    {{ selected.is_active ? 'Activa' : 'Inactiva' }}
                </span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Clase</dt>
                    <dd>{{ accountTypes[selected.account_type] ?? selected.account_type }}</dd>
                </div>
                <div>
                    <dt>Naturaleza</dt>
                    <dd>{{ selected.normal_balance === 'debit' ? 'Débito' : 'Crédito' }}</dd>
                </div>
                <div>
                    <dt>Moneda</dt>
                    <dd>{{ currencyLabels[selected.currency_mode] }}</dd>
                </div>
                <div>
                    <dt>Cuenta hoja (acepta movimientos)</dt>
                    <dd>{{ selected.accepts_posting ? 'Sí' : 'No — es una cuenta mayor' }}</dd>
                </div>
                <div>
                    <dt>Atributos</dt>
                    <dd class="badges">
                        <span v-if="selected.requires_business_partner" class="badge badge-neutral">Exige socio</span>
                        <span v-if="selected.is_cash_account" class="badge badge-neutral">Monetaria</span>
                        <span v-if="selected.requires_cost_center" class="badge badge-neutral">Exige norma</span>
                        <span v-if="!selected.requires_business_partner && !selected.is_cash_account && !selected.requires_cost_center">—</span>
                    </dd>
                </div>
                <div>
                    <dt>IVA</dt>
                    <dd>
                        {{ selected.tax_classification !== 'none' ? taxLabels[selected.tax_classification] : '—' }}
                        <span v-if="taxRateLabel(selected)" class="muted"> · {{ taxRateLabel(selected) }}</span>
                    </dd>
                </div>
                <div v-if="selected.description_en" class="full">
                    <dt>Nombre en inglés</dt>
                    <dd>{{ selected.description_en }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="account-form" @submit.prevent="submit">
                <div class="form-grid">
                    <div class="field">
                        <label for="account-code">Código</label>
                        <input id="account-code" v-model="form.code" type="text" placeholder="x-xx-xx-xx-xxx" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="account-type">Tipo (clase)</label>
                        <select id="account-type" v-model="form.account_type" required>
                            <option v-for="(label, key) in accountTypes" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field span-full">
                        <label for="account-name">Nombre</label>
                        <input id="account-name" v-model="form.description_es" type="text" required>
                        <span v-if="form.errors.description_es" class="error">{{ form.errors.description_es }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="account-name-en">Nombre (inglés, opcional)</label>
                        <input id="account-name-en" v-model="form.description_en" type="text">
                    </div>
                    <div class="field">
                        <label for="account-currency">Moneda</label>
                        <select id="account-currency" v-model="form.currency_mode">
                            <option value="local">Local</option>
                            <option value="foreign">Extranjera</option>
                            <option value="both">Ambas</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="account-tax-class">Clasificación IVA</label>
                        <select id="account-tax-class" v-model="form.tax_classification">
                            <option value="none">Ninguno</option>
                            <option value="sales">Ventas</option>
                            <option value="purchases">Compras</option>
                            <option value="iva_general">IVA General</option>
                            <option value="iva_devengado">IVA Devengado</option>
                            <option value="iva_soportado">IVA Soportado</option>
                        </select>
                    </div>
                    <div class="field span-full">
                        <label for="account-tax-rate">Indicador de impuesto vinculado (opcional)</label>
                        <select id="account-tax-rate" v-model="form.tax_rate_id">
                            <option :value="null">— Esta cuenta no recibe impuesto derivado —</option>
                            <option v-for="r in taxRates" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }} ({{ r.percentage }}%)</option>
                        </select>
                        <span class="muted small">Si se elige uno, al armar un asiento se puede escoger este indicador en una línea y el sistema deriva el impuesto directo a esta cuenta.</span>
                    </div>
                </div>

                <div class="checks">
                    <label class="check"><input v-model="form.accepts_posting" type="checkbox"> Cuenta hoja (acepta movimientos)</label>
                    <label class="check"><input v-model="form.requires_business_partner" type="checkbox"> Exige socio de negocio (CxC/CxP)</label>
                    <label class="check"><input v-model="form.is_cash_account" type="checkbox"> Cuenta monetaria (elegible para bancos)</label>
                    <label class="check"><input v-model="form.requires_cost_center" type="checkbox"> Exige norma de reparto en cada línea</label>
                    <label class="check"><input v-model="form.is_active" type="checkbox"> Activa</label>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link
                        v-if="canReconcile(selected)"
                        :href="route('account-reconciliation.index', selected.id)"
                        class="btn btn-ghost"
                        title="Reconciliación interna: vincular movimientos de esta cuenta entre sí"
                    >Reconciliar</Link>
                    <button type="button" class="btn btn-ghost" @click="showMovements"><ScrollTextIcon /> Ver movimientos</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="account-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>

        <LedgerPanel
            :open="ledger.open"
            dimension="account"
            :owner-id="ledger.ownerId"
            :owner-label="ledger.ownerLabel"
            @close="closeLedger"
        />
    </AppLayout>
</template>

<style scoped>
.cabinet {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.drawer-unit + .drawer-unit {
    margin-top: 0.1rem;
}

.drawer {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.95rem 1.2rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: linear-gradient(180deg, var(--color-surface) 0%, var(--color-surface-alt) 100%);
    cursor: pointer;
    text-align: left;
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--color-text);
    /*
        Sombra por capas (no una sola, "sombras sorprendentes"): una muy
        pegada al borde para el filo, una media para el volumen del cajón, y
        una larga y difusa para que parezca despegado de la página — el
        mismo truco que usan las tarjetas "flotantes" de Stripe/Linear, no
        una sombra plana de toda la vida.
    */
    box-shadow:
        0 1px 2px rgba(11, 31, 58, 0.06),
        0 6px 12px -4px rgba(11, 31, 58, 0.14),
        0 20px 40px -12px rgba(11, 31, 58, 0.22);
    transform: translateY(0);
    transition: transform .18s cubic-bezier(.2, .8, .2, 1), box-shadow .18s ease, background-color .12s ease, border-color .12s ease;
}

.drawer:hover {
    background: var(--color-primary-soft);
    transform: translateY(-3px);
    box-shadow:
        0 2px 4px rgba(11, 31, 58, 0.08),
        0 10px 20px -6px rgba(11, 31, 58, 0.18),
        0 28px 56px -16px rgba(11, 31, 58, 0.28);
}

.drawer.open {
    border-color: var(--color-primary);
    background: var(--color-primary-soft);
    transform: translateY(-1px);
}

.drawer-handle {
    width: 34px;
    height: 5px;
    border-radius: 3px;
    background: var(--color-border);
    flex-shrink: 0;
}

.drawer-label {
    flex: 1;
}

.drawer-count {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--color-text-muted);
    background: var(--color-surface-alt);
    border-radius: 999px;
    padding: 0.1rem 0.55rem;
}

/* El cajón abierto: la tabla de la clase, con su propio scroll vertical y
   el encabezado fijo (.table-scroll), así no hay que bajar hasta el fondo de
   cientos de cuentas para ver el cajón siguiente. */
.drawer-content {
    margin: 0.5rem 0 0.6rem;
    overflow: hidden;
    box-shadow:
        0 1px 2px rgba(11, 31, 58, 0.05),
        0 12px 28px -10px rgba(11, 31, 58, 0.20);
}

table { font-size: 0.82rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.badges { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.checks { display: flex; flex-direction: column; gap: 0.1rem; margin-top: 0.25rem; }

/*
    Cuenta mayor (accepts_posting=false, ej. "ACTIVOS CIRCULANTES"): nunca
    recibe movimientos directos, es un título que agrupa a las cuentas hoja
    de abajo. Mismo acento naranjo-marrón ya usado para los títulos del menú
    lateral (AppLayout.vue) — un solo lenguaje visual de "esto es un título
    de grupo" en toda la app, no un color nuevo por pantalla.
*/
.is-major-row td {
    font-weight: 800;
    color: #c1662d;
    background: var(--color-surface-alt);
    border-top: 1px solid rgba(193, 102, 45, 0.35);
}

.is-major-row td:first-child {
    box-shadow: inset 3px 0 0 0 #c1662d;
}

.table-responsive .is-major-row:hover td {
    background: rgba(193, 102, 45, 0.12);
}

/* En tarjetas (≤ 1024px), la cuenta mayor lleva el acento en el borde de la
   tarjeta entera, no en una celda. */
@media screen and (max-width: 1024px) {
    .drawer-content {
        overflow: visible;
        box-shadow: none;
    }

    .is-major-row {
        border-left: 3px solid #c1662d;
    }

    .is-major-row td,
    .is-major-row td:first-child {
        background: none;
        box-shadow: none;
    }
}
</style>
