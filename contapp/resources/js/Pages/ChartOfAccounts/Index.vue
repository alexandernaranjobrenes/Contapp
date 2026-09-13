<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

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

// --- formulario crear/editar (modal) ---

const editing = ref(null); // null = cerrado; {} = nueva cuenta; objeto = editando

const form = useForm({
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
});

function openCreate(type) {
    form.reset();
    form.account_type = type;
    editing.value = { isNew: true };
}

function openEdit(account) {
    form.clearErrors();
    form.code = account.code;
    form.description_es = account.description_es;
    form.description_en = account.description_en ?? '';
    form.account_type = account.account_type;
    form.currency_mode = account.currency_mode;
    form.tax_classification = account.tax_classification;
    form.tax_rate_id = account.tax_rate_id;
    form.accepts_posting = account.accepts_posting;
    form.requires_business_partner = account.requires_business_partner;
    form.is_cash_account = account.is_cash_account;
    form.requires_cost_center = account.requires_cost_center;
    form.is_active = account.is_active;
    editing.value = account;
}

function closeModal() {
    editing.value = null;
}

function submit() {
    if (editing.value.isNew) {
        form.post(route('chart-of-accounts.store'), { onSuccess: closeModal, preserveScroll: true });
    } else {
        form.put(route('chart-of-accounts.update', editing.value.id), { onSuccess: closeModal, preserveScroll: true });
    }
}

function destroy(account) {
    if (! confirm(`¿Eliminar la cuenta ${account.code} — ${account.description_es}? Esta acción no se puede deshacer.`)) return;

    router.delete(route('chart-of-accounts.destroy', account.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Catálogo de cuentas" />

    <AppLayout title="Catálogo de cuentas">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código o nombre..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate(Object.keys(accountTypes)[0])" />

        <div class="bulk-bar card">
            <div class="bulk-bar-row">
                <div class="bulk-bar-text">
                    <strong>Carga masiva</strong>
                    <span class="muted small">Descargá la plantilla, completala en Excel y subila para crear o actualizar cuentas por lote.</span>
                </div>
                <div class="bulk-actions">
                    <a :href="route('chart-of-accounts.template')" class="btn btn-ghost">Descargar plantilla</a>
                    <label class="btn btn-primary file-btn" :class="{ disabled: importForm.processing }">
                        {{ importForm.processing ? 'Subiendo...' : 'Importar XLSX' }}
                        <input ref="fileInput" type="file" accept=".xlsx" class="file-input" :disabled="importForm.processing" @change="onFileSelected">
                    </label>
                </div>
            </div>
            <span v-if="importForm.errors.file" class="error">{{ importForm.errors.file }}</span>

            <div v-if="importErrors.length" class="import-errors">
                <p class="import-errors-title">No se importó nada porque el archivo tiene {{ importErrors.length }} error(es). Corregilos y subilo de nuevo:</p>
                <ul>
                    <li v-for="(msg, i) in importErrors" :key="i">{{ msg }}</li>
                </ul>
            </div>
        </div>

        <div v-if="page.props.errors?.account" class="flash flash-error">{{ page.props.errors.account }}</div>

        <div class="cabinet">
            <div v-for="(label, type) in accountTypes" :key="type" class="drawer-unit">
                <button type="button" class="drawer" :class="{ open: openDrawer === type }" @click="toggleDrawer(type)">
                    <span class="drawer-handle"></span>
                    <span class="drawer-label">{{ label }}</span>
                    <span class="drawer-count">{{ grouped[type].length }}</span>
                </button>

                <div v-if="openDrawer === type" class="drawer-content card">
                    <div class="drawer-content-header">
                        <span class="muted">{{ grouped[type].length }} cuenta(s) en {{ label }}</span>
                        <button type="button" class="btn btn-primary" @click="openCreate(type)">+ Nueva cuenta</button>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Naturaleza</th>
                                <th>Moneda</th>
                                <th>Hoja</th>
                                <th>Atributos</th>
                                <th>IVA</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="account in grouped[type]"
                                :key="account.id"
                                class="clickable-row"
                                :class="{ 'is-major-row': !account.accepts_posting }"
                                title="Ver movimientos y saldo"
                                @click="openLedger(account)"
                            >
                                <td class="num code-cell">{{ account.code }}</td>
                                <td>{{ account.description_es }}</td>
                                <td>{{ account.normal_balance === 'debit' ? 'Débito' : 'Crédito' }}</td>
                                <td>{{ currencyLabels[account.currency_mode] }}</td>
                                <td>
                                    <span class="badge" :class="account.accepts_posting ? 'badge-success' : 'badge-neutral'">
                                        {{ account.accepts_posting ? 'Sí' : 'No' }}
                                    </span>
                                </td>
                                <td class="attrs-cell">
                                    <span v-if="account.requires_business_partner" class="badge badge-neutral" title="Exige socio de negocio">Socio</span>
                                    <span v-if="account.is_cash_account" class="badge badge-neutral" title="Cuenta monetaria (bancos)">Monetaria</span>
                                    <span v-if="account.requires_cost_center" class="badge badge-neutral" title="Exige norma de reparto">Norma</span>
                                </td>
                                <td>
                                    <span v-if="account.tax_classification !== 'none'" class="badge badge-neutral">
                                        {{ taxLabels[account.tax_classification] }}
                                    </span>
                                    <div v-if="taxRatesById[account.tax_rate_id]" class="muted small">
                                        {{ taxRatesById[account.tax_rate_id].code }} ({{ taxRatesById[account.tax_rate_id].percentage }}%)
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" :class="account.is_active ? 'badge-success' : 'badge-danger'">
                                        {{ account.is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="actions-cell" @click.stop>
                                    <button type="button" class="btn btn-ghost" @click="openEdit(account)">Editar</button>
                                    <Link
                                        v-if="account.accepts_posting && !account.requires_business_partner"
                                        :href="route('account-reconciliation.index', account.id)"
                                        class="btn btn-ghost"
                                        title="Reconciliación interna: vincular movimientos de esta cuenta entre sí"
                                    >Reconciliar</Link>
                                    <button type="button" class="btn btn-ghost" @click="destroy(account)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="!grouped[type].length">
                                <td colspan="9" class="muted empty-row">No hay cuentas en esta clase todavía.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal crear/editar cuenta -->
        <div v-if="editing" class="modal-backdrop" @click.self="closeModal">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>{{ editing.isNew ? 'Nueva cuenta' : `Editar cuenta ${editing.code}` }}</h2>

                <div class="grid">
                    <div class="field">
                        <label>Código</label>
                        <input v-model="form.code" type="text" placeholder="x-xx-xx-xx-xxx" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label>Tipo (clase)</label>
                        <select v-model="form.account_type" required>
                            <option v-for="(label, key) in accountTypes" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label>Nombre</label>
                        <input v-model="form.description_es" type="text" required>
                        <span v-if="form.errors.description_es" class="error">{{ form.errors.description_es }}</span>
                    </div>
                    <div class="field span-2">
                        <label>Nombre (inglés, opcional)</label>
                        <input v-model="form.description_en" type="text">
                    </div>
                    <div class="field">
                        <label>Moneda</label>
                        <select v-model="form.currency_mode">
                            <option value="local">Local</option>
                            <option value="foreign">Extranjera</option>
                            <option value="both">Ambas</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Clasificación IVA</label>
                        <select v-model="form.tax_classification">
                            <option value="none">Ninguno</option>
                            <option value="sales">Ventas</option>
                            <option value="purchases">Compras</option>
                            <option value="iva_general">IVA General</option>
                            <option value="iva_devengado">IVA Devengado</option>
                            <option value="iva_soportado">IVA Soportado</option>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label>Indicador de impuesto vinculado (opcional)</label>
                        <select v-model="form.tax_rate_id">
                            <option :value="null">— Esta cuenta no recibe impuesto derivado —</option>
                            <option v-for="r in taxRates" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }} ({{ r.percentage }}%)</option>
                        </select>
                        <span class="hint">Si se elige uno, al armar un asiento se puede escoger este indicador en una línea y el sistema deriva el impuesto directo a esta cuenta.</span>
                    </div>
                </div>

                <div class="checks">
                    <label><input v-model="form.accepts_posting" type="checkbox"> Cuenta hoja (acepta movimientos)</label>
                    <label><input v-model="form.requires_business_partner" type="checkbox"> Exige socio de negocio (CxC/CxP)</label>
                    <label><input v-model="form.is_cash_account" type="checkbox"> Cuenta monetaria (elegible para bancos)</label>
                    <label><input v-model="form.requires_cost_center" type="checkbox"> Exige norma de reparto en cada línea</label>
                    <label><input v-model="form.is_active" type="checkbox"> Activa</label>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeModal">Cancelar</button>
                </div>
            </form>
        </div>

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
.search-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    width: 220px;
}

.cabinet {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.bulk-bar {
    padding: 0.85rem 1.1rem;
    margin-bottom: 0.75rem;
}

.bulk-bar-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.bulk-bar-text {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    font-size: 0.85rem;
}

.bulk-actions {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}

.file-btn {
    position: relative;
    cursor: pointer;
    overflow: hidden;
}

.file-btn.disabled {
    opacity: 0.6;
    cursor: default;
}

.file-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    width: 100%;
    cursor: pointer;
}

.error {
    display: block;
    margin-top: 0.4rem;
    color: var(--color-danger);
    font-size: 0.76rem;
}

.import-errors {
    margin-top: 0.75rem;
    padding: 0.75rem 0.9rem;
    border-radius: var(--radius-sm);
    background: var(--color-danger-soft);
    color: var(--color-danger);
    font-size: 0.82rem;
}

.import-errors-title {
    font-weight: 700;
    margin: 0 0 0.4rem;
}

.import-errors ul {
    margin: 0;
    padding-left: 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

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

/*
    max-height + overflow-y: sin esto, un cajón con muchas cuentas crece sin
    límite y su propia barra de scroll horizontal (overflow-x) termina muy
    lejos, al fondo de cientos de filas — acá queda acotado a una franja
    visible, con scroll vertical Y horizontal propios, siempre alcanzables
    sin importar cuántas cuentas tenga ese tipo.
*/
.drawer-content {
    margin: 0.5rem 0 0.6rem;
    padding: 0;
    max-height: 60vh;
    overflow-y: auto;
    overflow-x: auto;
    border-radius: var(--radius-md);
    box-shadow:
        0 1px 2px rgba(11, 31, 58, 0.05),
        0 12px 28px -10px rgba(11, 31, 58, 0.20);
}

.drawer-content-header {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
}

/* Encabezado de columnas también fijo al scrollear el cajón verticalmente,
   justo debajo de la barra de título — mismo criterio, nada se pierde de
   vista al bajar por muchas filas. */
.drawer-content thead th {
    position: sticky;
    top: 2.55rem;
    z-index: 1;
    background: var(--color-surface);
}

/*
    Sin border-collapse (default: separate), cada celda dibuja su propio
    border-top por separado, y el border-spacing por defecto del navegador
    (no está en 0 acá) deja un huequito entre celda y celda — la línea
    horizontal de cada fila se ve cortada en segmentos en vez de una sola
    línea continua. collapse funde los bordes de celdas vecinas en una
    única línea real.
*/
table { font-size: 0.82rem; width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); white-space: nowrap; vertical-align: middle; }
/*
    Sin esto, una fila donde ninguna celda lleva badge (texto liso, más bajo)
    queda más baja que una fila con badges (que traen su propio padding) —
    filas de distinta altura se ven "desfasadas" al bajar la vista por la
    tabla. min-height empareja la altura mínima de fila sin importar si el
    contenido de esa celda es texto, un badge, o nada (celdas de atributos
    vacías cuando la cuenta no tiene ninguno).
*/
td { min-height: 1.6rem; box-sizing: content-box; }
.code-cell { text-align: left; font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.72rem; }
.empty-row { text-align: center; padding: 1.25rem; white-space: normal; }
.attrs-cell { display: flex; align-items: center; min-height: 1.6rem; gap: 0.3rem; flex-wrap: wrap; }
.actions-cell { display: flex; align-items: center; gap: 0.4rem; }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: var(--color-primary-soft); }

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

.is-major-row:hover td {
    background: rgba(193, 102, 45, 0.12);
}

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

.modal-card {
    width: 560px;
    max-width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
}

.modal-card h2 { font-size: 1rem; margin: 0 0 1rem; }

.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
.span-2 { grid-column: span 2; }

select, .modal-card input[type="text"] {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.checks {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin: 1rem 0;
    font-size: 0.85rem;
}

.checks label { display: flex; align-items: center; gap: 0.5rem; }

.modal-actions { display: flex; gap: 0.6rem; }

.hint {
    display: block;
    font-size: 0.74rem;
    color: var(--color-text-muted);
    margin-top: 0.2rem;
}
</style>
