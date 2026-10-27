<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    ArchiveRestoreIcon, ArrowLeftIcon, DownloadIcon, GitCompareArrowsIcon, LockKeyholeIcon, ShieldCheckIcon, TriangleAlertIcon, UploadIcon,
} from '@lucide/vue';
import { requestJson } from '../Utils/http';
import { fullDate } from '../Utils/relativeTime';
import { startCompanySwitch } from '../Utils/companySwitch';
import { confirmAction } from '../Utils/confirm';

/**
 * Exportar e importar el respaldo de una compañía (CLAUDE.md secc. 37). Lo
 * usan Administración → Respaldos (el Superusuario) y el backoffice: cambian
 * las rutas, a quién se le pide la contraseña y si se puede ir a la compañía.
 *
 * Exportar: qué y de qué período → el resumen («¿estás seguro?») → la
 * contraseña del archivo y la de la cuenta → baja el archivo.
 * Importar: el archivo y su contraseña → se revisa en la cola → lo que trae,
 * ya verificado → en una compañía nueva (sus datos) o completando una que ya
 * existe (se compara en la cola: qué le falta, qué ya tiene, qué lo impide) →
 * la contraseña de la cuenta → en cola. Cada paso de la cola muestra su avance.
 * Desde «Importaciones recientes» se vuelve a uno que espera confirmación (o
 * que la cola sigue trabajando), aunque se haya salido de la pantalla.
 */
const props = defineProps({
    companies: { type: Array, required: true },
    currentCompanyId: { type: Number, default: null },
    sections: { type: Array, required: true },
    license: { type: Object, required: true },
    minPassword: { type: Number, default: 10 },
    imports: { type: Array, default: () => [] },
    // { preview, export, import, compare, confirm, cancel, status }: nombres de ruta; params, los que llevan todas.
    routes: { type: Object, required: true },
    routeParams: { type: Object, default: () => ({}) },
    accountLabel: { type: String, default: 'Tu contraseña de Pivox' },
    backoffice: { type: Boolean, default: false },
});

const page = usePage();
const url = (name, extra = {}) => route(props.routes[name], { ...props.routeParams, ...extra });

// ── Exportar ──────────────────────────────────────────────────────────

const exportStep = ref('form'); // form | summary | done
const exportForm = ref({
    company_id: props.currentCompanyId ?? props.companies[0]?.id ?? null,
    sections: props.sections.map((s) => s.key),
    period: 'all',
    from: '',
    to: '',
    password: '',
    password_confirmation: '',
    account_password: '',
});
const exportSummary = ref(null);
const exportErrors = ref({});
const exportBusy = ref(false);
const exportMessage = ref('');

// Lo obligatorio siempre va; lo que otra sección necesita, también.
const requiredBy = computed(() => {
    const out = {};
    props.sections.forEach((s) => {
        if (exportForm.value.sections.includes(s.key)) {
            s.needs.forEach((need) => { (out[need] ??= []).push(s.label); });
        }
    });
    return out;
});

watch(() => [...exportForm.value.sections], () => {
    props.sections.forEach((s) => {
        if ((s.required || requiredBy.value[s.key]) && ! exportForm.value.sections.includes(s.key)) {
            exportForm.value.sections.push(s.key);
        }
    });
}, { immediate: true });

const lockedSection = (s) => s.required || !! requiredBy.value[s.key];

function exportPayload(withPasswords = false) {
    const f = exportForm.value;
    const base = { company_id: f.company_id, sections: f.sections, period: f.period, from: f.from || null, to: f.to || null };

    return withPasswords ? { ...base, password: f.password, password_confirmation: f.password_confirmation, account_password: f.account_password } : base;
}

async function reviewExport() {
    exportBusy.value = true;
    exportErrors.value = {};
    const result = await requestJson(url('preview'), { method: 'POST', body: exportPayload() });
    exportBusy.value = false;

    if (! result.ok) {
        exportErrors.value = result.status === 422 ? result.errors : { general: result.message };
        return;
    }

    exportSummary.value = result.data;
    exportStep.value = 'summary';
}

// La descarga va por fetch y no con un formulario: si la contraseña no es la
// correcta, el error vuelve acá y no se pierde lo elegido.
async function confirmExport() {
    exportBusy.value = true;
    exportErrors.value = {};

    let response;
    try {
        response = await fetch(url('export'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify(exportPayload(true)),
        });
    } catch {
        exportBusy.value = false;
        exportErrors.value = { general: 'No hay conexión con el servidor. Revisá tu conexión y probá de nuevo.' };
        return;
    }

    if (! response.ok) {
        exportBusy.value = false;
        const data = await response.json().catch(() => null);
        exportErrors.value = response.status === 422
            ? Object.fromEntries(Object.entries(data?.errors ?? {}).map(([k, v]) => [k, v[0]]))
            : { general: data?.message ?? 'No se pudo exportar el respaldo. Probá de nuevo.' };
        return;
    }

    const blob = await response.blob();
    const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'respaldo.txt';
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);

    exportBusy.value = false;
    exportMessage.value = `Listo: se descargó «${name}». Guardalo junto con su contraseña, en dos lugares distintos.`;
    exportForm.value.password = '';
    exportForm.value.password_confirmation = '';
    exportForm.value.account_password = '';
    exportStep.value = 'done';
}

function restartExport() {
    exportStep.value = 'form';
    exportSummary.value = null;
    exportMessage.value = '';
    exportErrors.value = {};
}

// ── Importar ──────────────────────────────────────────────────────────

// upload | inspecting | review | comparing | comparison | importing | done | failed
const importStep = ref('upload');
const importPanel = ref(null);
const importFile = ref(null);
const fileInput = ref(null);
const importPassword = ref('');
const importErrors = ref({});
const importBusy = ref(false);
// Lo último que dijo el servidor del respaldo: estado, avance, lo que trae
// (summary, defaults), la comparación y el resultado.
const pending = ref(null);
const destination = ref('new'); // new | merge
const mergeCompanyId = ref(null);
const companyForm = ref({ legal_name: '', trade_name: '', tax_id: '', account_password: '' });
const mergePassword = ref('');
let poll = null;

// El paso de la pantalla según lo que está haciendo el respaldo.
const STEP_OF = {
    inspecting: 'inspecting',
    validated: 'review',
    comparing: 'comparing',
    compared: 'comparison',
    queued: 'importing',
    importing: 'importing',
    done: 'done',
    failed: 'failed',
    expired: 'failed',
};

const RUNNING_STEPS = ['inspecting', 'comparing', 'importing'];

function pickFile(event) {
    importFile.value = event.target.files?.[0] ?? null;
}

/**
 * Lo que contestó el servidor: a qué paso va la pantalla, y si la cola
 * sigue trabajando, que se siga mirando.
 */
function show(data) {
    const step = STEP_OF[data.status] ?? 'failed';

    // Recién revisado: los datos que se proponen para la compañía nueva.
    if (step === 'review' && ['upload', 'inspecting'].includes(importStep.value) && data.defaults) {
        companyForm.value = {
            legal_name: data.defaults.legal_name,
            trade_name: data.defaults.trade_name ?? '',
            tax_id: data.defaults.tax_id ?? '',
            account_password: '',
        };
    }

    // La comparación falló: el respaldo vuelve a esperar, con el motivo.
    if (step === 'review' && importStep.value === 'comparing' && data.error) {
        importErrors.value = { general: data.error };
    }

    pending.value = { ...(pending.value ?? {}), ...data };
    importStep.value = step;

    if (RUNNING_STEPS.includes(step)) {
        watchStatus();
    } else {
        stopWatching();
    }

    if (['done', 'failed'].includes(step)) {
        router.reload({ only: ['imports', 'license'] });
    }
}

async function reviewImport() {
    if (! importFile.value) {
        importErrors.value = { file: 'Elegí el archivo del respaldo.' };
        return;
    }

    importBusy.value = true;
    importErrors.value = {};
    const body = new FormData();
    body.append('file', importFile.value);
    body.append('password', importPassword.value);

    const result = await requestJson(url('import'), { method: 'POST', body });
    importBusy.value = false;

    if (! result.ok) {
        importErrors.value = result.status === 422 ? result.errors : { general: result.message };
        return;
    }

    importPassword.value = '';
    pending.value = null;
    show(result.data);
    router.reload({ only: ['imports'] });
}

/** Lo que pidió la persona, a la cola; si el servidor lo rechaza, el motivo queda junto al campo. */
async function send(name, body) {
    importBusy.value = true;
    importErrors.value = {};
    const result = await requestJson(url(name, { uuid: pending.value.uuid }), { method: 'POST', body });
    importBusy.value = false;

    if (! result.ok) {
        importErrors.value = result.status === 422 ? result.errors : { general: result.message };
        return false;
    }

    show(result.data);
    router.reload({ only: ['imports'] });

    return true;
}

/**
 * Uno de la lista: la pantalla vuelve a donde quedó (lo que trae, la
 * comparación o el avance de la cola), para confirmarlo o descartarlo.
 */
async function resumeImport(item) {
    const result = await requestJson(url('status', { uuid: item.uuid }));

    if (! result.ok) {
        importErrors.value = { general: result.message };
        return;
    }

    restartImport();
    const data = result.data;

    if (data.defaults) {
        companyForm.value = { legal_name: data.defaults.legal_name, trade_name: data.defaults.trade_name ?? '', tax_id: data.defaults.tax_id ?? '', account_password: '' };
    }

    destination.value = data.mode === 'merge' ? 'merge' : 'new';
    mergeCompanyId.value = data.target_company_id ?? null;
    show(data);
    importPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** No importarlo: se borran el archivo subido y su contraseña. */
function discardImport() {
    confirmAction({
        title: 'Descartar el respaldo',
        message: 'No se importa nada, y se borran el archivo subido y su contraseña. Para importarlo después, hay que volver a subirlo.',
        confirmLabel: 'Descartar',
        danger: true,
        onConfirm: async () => {
            importBusy.value = true;
            importErrors.value = {};
            const result = await requestJson(url('cancel', { uuid: pending.value.uuid }), { method: 'DELETE' });
            importBusy.value = false;

            if (! result.ok) {
                importErrors.value = { general: result.status === 422 ? Object.values(result.errors)[0] : result.message };
                return;
            }

            restartImport();
            router.reload({ only: ['imports'] });
        },
    });
}

function confirmImport() {
    return send('confirm', { mode: 'new', ...companyForm.value });
}

function compareImport() {
    if (! mergeCompanyId.value) {
        importErrors.value = { company_id: 'Elegí qué compañía completar.' };
        return;
    }

    return send('compare', { company_id: mergeCompanyId.value });
}

async function confirmMerge() {
    if (await send('confirm', { mode: 'merge', account_password: mergePassword.value })) {
        mergePassword.value = '';
    }
}

function backToReview() {
    importStep.value = 'review';
    importErrors.value = {};
}

function watchStatus() {
    if (poll) return;

    poll = setInterval(async () => {
        const result = await requestJson(url('status', { uuid: pending.value.uuid }));

        if (result.ok) show(result.data);
    }, 1500);
}

function stopWatching() {
    clearInterval(poll);
    poll = null;
}

onBeforeUnmount(stopWatching);

function restartImport() {
    stopWatching();
    importStep.value = 'upload';
    pending.value = null;
    importFile.value = null;
    importErrors.value = {};
    destination.value = 'new';
    mergeCompanyId.value = null;
    mergePassword.value = '';

    if (fileInput.value) fileInput.value.value = '';
}

// El cambio de compañía, con su ventana (CLAUDE.md secc. 30). Las de la
// barra superior traen el tema y el estado de la licencia; una recién creada
// todavía no está entre ellas.
function goToCompany(companyId, name) {
    const mine = page.props.companies ?? [];
    const to = mine.find((c) => c.id === companyId);
    const from = mine.find((c) => c.id === page.props.currentCompanyId);

    startCompanySwitch({
        to: { id: companyId, name: to ? (to.trade_name || to.legal_name) : name, theme: to?.theme || 'marino', licenseState: to?.license_state ?? 'ok' },
        from: from ? { id: from.id, name: from.trade_name || from.legal_name } : null,
        revert: () => {},
    });
}

// ── Mientras trabaja la cola ──────────────────────────────────────────

const percent = computed(() => {
    const total = pending.value?.total ?? 0;

    return importStep.value !== 'inspecting' && total > 0 ? Math.min(100, Math.round(((pending.value.progress ?? 0) / total) * 100)) : null;
});

const companyNameOf = (id) => props.companies.find((c) => c.id === id)?.name ?? '';

const runningTitle = computed(() => {
    if (importStep.value === 'inspecting') return 'Revisando el archivo…';
    if (importStep.value === 'comparing') return `Comparando con «${companyNameOf(pending.value?.target_company_id)}»…`;
    if (pending.value?.mode === 'merge') return `Completando «${pending.value.company_name}»…`;

    return `Importando «${pending.value?.company_name || companyForm.value.trade_name || companyForm.value.legal_name}»…`;
});

const progressText = computed(() => {
    const done = number(pending.value?.progress);

    return importStep.value === 'inspecting' ? `${done} registros revisados` : `${done} de ${number(pending.value?.total)} registros`;
});

// ── La comparación ────────────────────────────────────────────────────

const comparison = computed(() => pending.value?.comparison ?? null);
const blocked = computed(() => (comparison.value?.conflicts_total ?? 0) > 0);
const nothingToAdd = computed(() => comparison.value && ! comparison.value.totals.added && ! comparison.value.people?.added?.length);

function openingNote(opening) {
    if (! opening || (! opening.added && ! opening.skipped)) return null;

    return opening.added
        ? `Los saldos al ${opening.date} entran: esta compañía no tiene movimientos antes del ${opening.from}.`
        : `Los saldos al ${opening.date} no entran: esta compañía ya tiene movimientos antes del ${opening.from}, y con ellos esos saldos.`;
}

function mergeCount(item) {
    const present = item.present ? `${number(item.present)} ya ${item.present === 1 ? 'está' : 'están'}` : '';
    const different = item.different ? `${number(item.different)} ${item.different === 1 ? 'distinto' : 'distintos'}` : '';

    return [present, different].filter(Boolean).join(', ');
}

// ── Resúmenes ─────────────────────────────────────────────────────────

const number = (n) => new Intl.NumberFormat('es-CR').format(n ?? 0);

function openingText(opening, date) {
    if (! date || ! opening) return null;
    const parts = [];
    if (opening.accounts) parts.push(`${number(opening.accounts)} cuentas`);
    if (opening.open_items) parts.push(`${number(opening.open_items)} partidas abiertas`);
    if (opening.items) parts.push(`${number(opening.items)} artículos con existencias`);
    if (opening.employees) parts.push(`${number(opening.employees)} empleados con vacaciones`);

    return parts.length ? `Saldos al ${date}: ${parts.join(', ')}.` : `Sin saldos anteriores al ${date}.`;
}

const STATUS = {
    inspecting: ['Revisándose', 'badge-warning'],
    validated: ['Esperando confirmación', 'badge-neutral'],
    comparing: ['Comparándose', 'badge-warning'],
    compared: ['Comparado', 'badge-neutral'],
    queued: ['En cola', 'badge-neutral'],
    importing: ['Importando', 'badge-warning'],
    done: ['Importado', 'badge-success'],
    failed: ['Con error', 'badge-danger'],
    expired: ['Vencido', 'badge-neutral'],
    cancelled: ['Descartado', 'badge-neutral'],
};

const droppedTotal = computed(() => Object.values(pending.value?.result?.dropped ?? {}).reduce((a, b) => a + b, 0));

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}
</script>

<template>
    <div class="backups">
        <!-- ── Exportar ── -->
        <section class="card panel" aria-labelledby="export-title">
            <header class="panel-head">
                <DownloadIcon aria-hidden="true" />
                <div>
                    <h2 id="export-title">Exportar un respaldo</h2>
                    <p class="hint">Toda la información de una compañía, en un archivo de texto cifrado con la contraseña que elijas.</p>
                </div>
            </header>

            <form v-if="exportStep === 'form'" class="form-grid" @submit.prevent="reviewExport">
                <div class="field">
                    <label for="backup-company">Compañía</label>
                    <select id="backup-company" v-model="exportForm.company_id" required>
                        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}{{ c.tax_id ? ` (${c.tax_id})` : '' }}</option>
                    </select>
                    <span v-if="exportErrors.company_id" class="error">{{ exportErrors.company_id }}</span>
                </div>

                <fieldset class="field span-full">
                    <legend>Qué exportar</legend>
                    <div class="sections">
                        <label v-for="s in sections" :key="s.key" class="check section-check">
                            <input v-model="exportForm.sections" type="checkbox" :value="s.key" :disabled="lockedSection(s)">
                            <span>
                                {{ s.label }}
                                <small v-if="s.required" class="muted">Siempre va: lo demás se apoya en esto.</small>
                                <small v-else-if="requiredBy[s.key]" class="muted">La necesita {{ requiredBy[s.key].join(' y ') }}.</small>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="field span-full">
                    <legend>Período</legend>
                    <div class="period">
                        <label class="check"><input v-model="exportForm.period" type="radio" value="all"> Todo el historial</label>
                        <label class="check"><input v-model="exportForm.period" type="radio" value="range"> Un período</label>
                    </div>
                    <div v-if="exportForm.period === 'range'" class="field-row">
                        <div class="field">
                            <label for="backup-from">Desde</label>
                            <input id="backup-from" v-model="exportForm.from" type="date" :max="exportForm.to || undefined" required>
                            <span v-if="exportErrors.from" class="error">{{ exportErrors.from }}</span>
                        </div>
                        <div class="field">
                            <label for="backup-to">Hasta</label>
                            <input id="backup-to" v-model="exportForm.to" type="date" :min="exportForm.from || undefined">
                            <span class="hint small">Vacío: hasta hoy.</span>
                            <span v-if="exportErrors.to" class="error">{{ exportErrors.to }}</span>
                        </div>
                    </div>
                    <p v-if="exportForm.period === 'range'" class="hint small">
                        Los catálogos (cuentas, socios, artículos, empleados) van completos; los movimientos, los del período. Lleva además los
                        saldos del día anterior, para que la compañía arranque bien si se importa.
                    </p>
                </fieldset>

                <p v-if="exportErrors.general" class="flash flash-error span-full" role="alert">{{ exportErrors.general }}</p>

                <div class="form-actions span-full">
                    <button type="submit" class="btn btn-primary" :disabled="exportBusy" :data-busy="exportBusy ? '' : null">
                        {{ exportBusy ? 'Revisando…' : 'Revisar' }}
                    </button>
                </div>
            </form>

            <form v-else-if="exportStep === 'summary'" class="summary" @submit.prevent="confirmExport">
                <h3>¿Estás seguro de exportar este respaldo?</h3>
                <dl class="facts">
                    <div><dt>Compañía</dt><dd>{{ exportSummary.company }}</dd></div>
                    <div><dt>Período</dt><dd>{{ exportSummary.period }}</dd></div>
                    <div><dt>Registros</dt><dd>{{ number(exportSummary.total) }}</dd></div>
                </dl>

                <div class="counts">
                    <div v-for="s in exportSummary.sections" :key="s.key" class="count-group">
                        <h4>{{ s.label }}</h4>
                        <ul>
                            <li v-for="i in s.items" :key="i.label"><span>{{ i.label }}</span><strong>{{ number(i.count) }}</strong></li>
                        </ul>
                    </div>
                </div>

                <p v-if="openingText(exportSummary.opening, exportSummary.opening_date)" class="hint">{{ openingText(exportSummary.opening, exportSummary.opening_date) }}</p>

                <div class="notice">
                    <LockKeyholeIcon aria-hidden="true" />
                    <p>
                        El archivo va cifrado con esta contraseña. <strong>Guardala:</strong> sin ella el respaldo no se puede abrir, y nadie la puede
                        recuperar. Queda en la Bitácora que lo exportaste<template v-if="backoffice">, y al Superusuario le llega un correo</template>.
                    </p>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="backup-password">Contraseña del archivo</label>
                        <input id="backup-password" v-model="exportForm.password" type="password" autocomplete="new-password" :minlength="minPassword" required>
                        <span class="hint small">Al menos {{ minPassword }} caracteres.</span>
                        <span v-if="exportErrors.password" class="error">{{ exportErrors.password }}</span>
                    </div>
                    <div class="field">
                        <label for="backup-password-2">Repetila</label>
                        <input id="backup-password-2" v-model="exportForm.password_confirmation" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="field">
                        <label for="backup-account">{{ accountLabel }}</label>
                        <input id="backup-account" v-model="exportForm.account_password" type="password" autocomplete="current-password" required>
                        <span v-if="exportErrors.account_password" class="error">{{ exportErrors.account_password }}</span>
                    </div>
                </div>

                <p v-if="exportErrors.general" class="flash flash-error" role="alert">{{ exportErrors.general }}</p>

                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" :disabled="exportBusy" @click="exportStep = 'form'">Volver</button>
                    <button type="submit" class="btn btn-primary" :disabled="exportBusy" :data-busy="exportBusy ? '' : null">
                        <DownloadIcon /> {{ exportBusy ? 'Exportando…' : 'Sí, exportar' }}
                    </button>
                </div>
            </form>

            <div v-else class="done">
                <p class="flash flash-success" role="status"><ShieldCheckIcon aria-hidden="true" /> {{ exportMessage }}</p>
                <button type="button" class="btn btn-ghost" @click="restartExport">Exportar otro</button>
            </div>
        </section>

        <!-- ── Importar ── -->
        <section ref="importPanel" class="card panel" aria-labelledby="import-title">
            <header class="panel-head">
                <ArchiveRestoreIcon aria-hidden="true" />
                <div>
                    <h2 id="import-title">Importar un respaldo</h2>
                    <p class="hint">En una compañía nueva, o completando una que ya existe con lo que le falta. Lo que ya está nunca se cambia.</p>
                </div>
            </header>

            <form v-if="importStep === 'upload'" class="form-grid" @submit.prevent="reviewImport">
                <div class="field">
                    <label for="import-file">Archivo del respaldo</label>
                    <input id="import-file" ref="fileInput" type="file" accept=".txt,text/plain" required @change="pickFile">
                    <span v-if="importErrors.file" class="error">{{ importErrors.file }}</span>
                </div>
                <div class="field">
                    <label for="import-password">Contraseña del archivo</label>
                    <input id="import-password" v-model="importPassword" type="password" autocomplete="off" required>
                    <span v-if="importErrors.password" class="error">{{ importErrors.password }}</span>
                </div>
                <p v-if="importErrors.general" class="flash flash-error span-full" role="alert">{{ importErrors.general }}</p>
                <div class="form-actions span-full">
                    <button type="submit" class="btn btn-primary" :disabled="importBusy" :data-busy="importBusy ? '' : null">
                        <UploadIcon /> {{ importBusy ? 'Subiendo…' : 'Revisar' }}
                    </button>
                </div>
            </form>

            <!-- Revisando, comparando o importando: la cola, con su avance. -->
            <div v-else-if="RUNNING_STEPS.includes(importStep)" class="importing" role="status" aria-live="polite">
                <span class="spinner" aria-hidden="true"></span>
                <div class="importing-text">
                    <p>
                        <strong>{{ runningTitle }}</strong><br>
                        <span class="muted">
                            {{ importStep === 'comparing' ? 'Se hace todo y se deshace: no se guarda nada.' : 'Puede tardar unos minutos. Podés salir de esta pantalla: sigue solo.' }}
                        </span>
                    </p>
                    <div
                        v-if="percent !== null"
                        class="bar"
                        role="progressbar"
                        :aria-valuenow="percent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="runningTitle"
                    >
                        <span :style="{ width: `${percent}%` }"></span>
                    </div>
                    <p class="muted small">{{ progressText }}</p>
                </div>
            </div>

            <form v-else-if="importStep === 'review'" class="summary" @submit.prevent="destination === 'new' ? confirmImport() : compareImport()">
                <h3>¿Qué hacer con este respaldo?</h3>
                <p class="verified"><ShieldCheckIcon aria-hidden="true" /> Lo generó esta instalación de Pivox y no fue modificado.</p>
                <dl class="facts">
                    <div><dt>Compañía de origen</dt><dd>{{ pending.summary.company.trade_name || pending.summary.company.legal_name }}{{ pending.summary.company.tax_id ? ` (${pending.summary.company.tax_id})` : '' }}</dd></div>
                    <div><dt>Período</dt><dd>{{ pending.summary.period }}</dd></div>
                    <div><dt>Exportado</dt><dd>{{ pending.summary.exported.por }} ({{ pending.summary.exported.como }}), {{ fullDate(pending.summary.exported.el) }}</dd></div>
                    <div><dt>Registros</dt><dd>{{ number(pending.summary.total) }}</dd></div>
                </dl>

                <div class="counts">
                    <div v-for="s in pending.summary.sections" :key="s.key" class="count-group">
                        <h4>{{ s.label }}</h4>
                        <ul>
                            <li v-for="i in s.items" :key="i.label"><span>{{ i.label }}</span><strong>{{ number(i.count) }}</strong></li>
                        </ul>
                    </div>
                </div>

                <p v-if="openingText(pending.summary.opening, pending.summary.opening_date)" class="hint">{{ openingText(pending.summary.opening, pending.summary.opening_date) }}</p>

                <fieldset class="field destination">
                    <legend>Dónde importarlo</legend>
                    <div class="period">
                        <label class="check"><input v-model="destination" type="radio" value="new"> En una compañía nueva</label>
                        <label class="check">
                            <input v-model="destination" type="radio" value="merge" :disabled="! companies.length"> Completar una compañía que ya existe
                        </label>
                    </div>
                </fieldset>

                <template v-if="destination === 'new'">
                    <h4 class="new-company">La compañía nueva <span class="muted small">({{ license.companies }} de {{ license.max_companies }} compañías de la licencia)</span></h4>
                    <p v-if="! pending.can_add_company" class="flash flash-error" role="alert">
                        La licencia ya alcanzó el máximo de compañías: hace falta más cupo antes de importar.
                    </p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="import-legal">Razón social</label>
                            <input id="import-legal" v-model="companyForm.legal_name" type="text" maxlength="255" required>
                            <span v-if="importErrors.legal_name" class="error">{{ importErrors.legal_name }}</span>
                        </div>
                        <div class="field">
                            <label for="import-trade">Nombre comercial</label>
                            <input id="import-trade" v-model="companyForm.trade_name" type="text" maxlength="255">
                        </div>
                        <div class="field">
                            <label for="import-tax">Cédula jurídica</label>
                            <input id="import-tax" v-model="companyForm.tax_id" type="text" maxlength="50">
                        </div>
                        <div class="field">
                            <label for="import-account">{{ accountLabel }}</label>
                            <input id="import-account" v-model="companyForm.account_password" type="password" autocomplete="current-password" required>
                            <span v-if="importErrors.account_password" class="error">{{ importErrors.account_password }}</span>
                        </div>
                    </div>
                    <p class="hint small">
                        Las personas entran por su correo, si ya tienen cuenta en Pivox y caben en la licencia. Lo contabilizado entra contabilizado,
                        con los mismos números de documento.
                    </p>
                </template>

                <template v-else>
                    <div class="form-grid">
                        <div class="field">
                            <label for="merge-company">Compañía que se completa</label>
                            <select id="merge-company" v-model="mergeCompanyId" required>
                                <option :value="null" disabled>Elegí una</option>
                                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}{{ c.tax_id ? ` (${c.tax_id})` : '' }}</option>
                            </select>
                            <span v-if="importErrors.company_id" class="error">{{ importErrors.company_id }}</span>
                        </div>
                    </div>
                    <p class="hint small">
                        Primero se compara, sin guardar nada: qué le falta, qué ya tiene y si algo lo impide. Lo que ya está no se cambia; lo que el
                        sistema calcula con los movimientos (saldos de partidas, existencias, costo promedio, consecutivos) se recalcula con lo que se agrega.
                    </p>
                </template>

                <p v-if="importErrors.general" class="flash flash-error" role="alert">{{ importErrors.general }}</p>

                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" :disabled="importBusy" @click="discardImport">Descartar</button>
                    <button
                        v-if="destination === 'new'"
                        type="submit"
                        class="btn btn-primary"
                        :disabled="importBusy || ! pending.can_add_company"
                        :data-busy="importBusy ? '' : null"
                    >
                        <ArchiveRestoreIcon /> {{ importBusy ? 'Enviando…' : 'Sí, importar' }}
                    </button>
                    <button v-else type="submit" class="btn btn-primary" :disabled="importBusy" :data-busy="importBusy ? '' : null">
                        <GitCompareArrowsIcon /> {{ importBusy ? 'Enviando…' : 'Comparar' }}
                    </button>
                </div>
            </form>

            <form v-else-if="importStep === 'comparison'" class="summary" @submit.prevent="confirmMerge">
                <h3 v-if="blocked">No se puede completar «{{ comparison.company.name }}» con este respaldo</h3>
                <h3 v-else-if="nothingToAdd">A «{{ comparison.company.name }}» no le falta nada de este respaldo</h3>
                <h3 v-else>¿Completar «{{ comparison.company.name }}» con lo que le falta?</h3>

                <div v-if="comparison.other_tax_id" class="notice">
                    <TriangleAlertIcon aria-hidden="true" />
                    <p>
                        El respaldo es de la cédula {{ comparison.other_tax_id }} y esta compañía es la {{ comparison.company.tax_id }}: probablemente es
                        otra compañía. Cada cosa se reconoce por su código o su número, así que revisá bien lo que se agregaría.
                    </p>
                </div>

                <div v-if="blocked" class="flash flash-error conflicts" role="alert">
                    <p>
                        <strong>Lo que lo impide</strong><template v-if="comparison.conflicts_total > comparison.conflicts.length">
                            ({{ comparison.conflicts.length }} de {{ number(comparison.conflicts_total) }})</template>:
                    </p>
                    <ul>
                        <li v-for="c in comparison.conflicts" :key="c">{{ c }}</li>
                    </ul>
                    <p>
                        Completar no cambia lo que ya está, y traer lo del respaldo lo duplicaría o lo dejaría a medias. Resolvelo en esta compañía y
                        volvé a comparar, o importá el respaldo en una compañía nueva.
                    </p>
                </div>

                <dl class="facts">
                    <div><dt>Le falta</dt><dd>{{ number(comparison.totals.added) }} registros</dd></div>
                    <div><dt>Ya tiene</dt><dd>{{ number(comparison.totals.present) }}</dd></div>
                    <div><dt>Distintos (queda lo de acá)</dt><dd>{{ number(comparison.totals.different) }}</dd></div>
                    <div v-if="comparison.totals.dropped"><dt>No entran</dt><dd>{{ number(comparison.totals.dropped) }}</dd></div>
                </dl>

                <div class="counts">
                    <div v-for="s in comparison.sections" :key="s.key" class="count-group">
                        <h4>{{ s.label }}</h4>
                        <ul>
                            <li v-for="i in s.items" :key="i.label">
                                <span>{{ i.label }}<small v-if="mergeCount(i)" class="muted"> · {{ mergeCount(i) }}</small></span>
                                <strong :class="{ muted: ! i.added }">{{ i.added ? `+${number(i.added)}` : '0' }}</strong>
                            </li>
                        </ul>
                    </div>
                </div>

                <p v-if="openingNote(comparison.opening)" class="hint">{{ openingNote(comparison.opening) }}</p>
                <ul class="result">
                    <li v-if="comparison.people?.added?.length">Entrarían: {{ comparison.people.added.join(', ') }}.</li>
                    <li v-if="comparison.people?.missing?.length">Sin cuenta en Pivox (invitalas desde Usuarios): {{ comparison.people.missing.join(', ') }}.</li>
                    <li v-if="comparison.people?.no_quota?.length">No caben en la licencia: {{ comparison.people.no_quota.join(', ') }}.</li>
                    <li v-if="comparison.totals.dropped">
                        {{ number(comparison.totals.dropped) }} registros no entran: dependen de algo que no tiene ni esta compañía ni el respaldo.
                    </li>
                </ul>

                <template v-if="! blocked && ! nothingToAdd">
                    <div class="form-grid">
                        <div class="field">
                            <label for="merge-account">{{ accountLabel }}</label>
                            <input id="merge-account" v-model="mergePassword" type="password" autocomplete="current-password" required>
                            <span v-if="importErrors.account_password" class="error">{{ importErrors.account_password }}</span>
                        </div>
                    </div>
                    <p class="hint small">Mientras se completa, conviene que nadie trabaje en esa compañía.</p>
                </template>

                <p v-if="importErrors.general || importErrors.company_id" class="flash flash-error" role="alert">{{ importErrors.general ?? importErrors.company_id }}</p>

                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" :disabled="importBusy" @click="backToReview"><ArrowLeftIcon /> Volver</button>
                    <button type="button" class="btn btn-ghost" :disabled="importBusy" @click="discardImport">Descartar</button>
                    <button v-if="! blocked && ! nothingToAdd" type="submit" class="btn btn-primary" :disabled="importBusy" :data-busy="importBusy ? '' : null">
                        <ArchiveRestoreIcon /> {{ importBusy ? 'Enviando…' : 'Sí, completar' }}
                    </button>
                </div>
            </form>

            <div v-else-if="importStep === 'done'" class="done">
                <p class="flash flash-success" role="status">
                    <ShieldCheckIcon aria-hidden="true" />
                    <template v-if="pending.mode === 'merge'">Listo: se le agregaron {{ number(pending.result?.imported) }} registros a «{{ pending.company_name }}».</template>
                    <template v-else>Listo: se creó «{{ pending.company_name }}» con {{ number(pending.result?.imported) }} registros.</template>
                </p>
                <ul class="result">
                    <li v-if="pending.result?.people?.added?.length">Con acceso: {{ pending.result.people.added.join(', ') }}.</li>
                    <li v-if="pending.result?.people?.missing?.length">
                        Sin cuenta en Pivox (invitalas desde Usuarios): {{ pending.result.people.missing.join(', ') }}.
                    </li>
                    <li v-if="pending.result?.people?.no_quota?.length">
                        No cupieron en la licencia: {{ pending.result.people.no_quota.join(', ') }}.
                    </li>
                    <li v-if="droppedTotal">
                        Quedaron fuera {{ number(droppedTotal) }} registros que dependían de movimientos anteriores al período.
                    </li>
                </ul>
                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" @click="restartImport">Importar otro</button>
                    <button v-if="! backoffice" type="button" class="btn btn-primary" @click="goToCompany(pending.company_id, pending.company_name)">Ir a la compañía</button>
                </div>
            </div>

            <div v-else class="done">
                <p class="flash flash-error" role="alert">{{ pending?.error ?? 'No se pudo importar.' }}</p>
                <button type="button" class="btn btn-ghost" @click="restartImport">Volver a intentar</button>
            </div>
        </section>

        <!-- ── Importaciones recientes ── -->
        <section v-if="imports.length" class="card panel" aria-labelledby="imports-title">
            <h2 id="imports-title" class="panel-title">Importaciones recientes</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Fecha</th><th>Respaldo de</th><th>Período</th><th>Estado</th><th>Compañía</th><th><span class="sr-only">Acciones</span></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in imports" :key="i.uuid">
                            <td data-label="Fecha">{{ fullDate(i.created_at) }}</td>
                            <td data-label="Respaldo de">{{ i.source }}</td>
                            <td data-label="Período">{{ i.period }}</td>
                            <td data-label="Estado"><span class="badge" :class="STATUS[i.status]?.[1]">{{ STATUS[i.status]?.[0] ?? i.status }}</span></td>
                            <td data-label="Compañía">
                                <template v-if="i.status === 'done'">{{ i.mode === 'merge' ? `Completó ${i.company_name}` : `Creó ${i.company_name}` }}</template>
                                <template v-else>{{ i.error ?? '—' }}</template>
                            </td>
                            <td class="row-action">
                                <button
                                    v-if="i.resumable && pending?.uuid !== i.uuid"
                                    type="button"
                                    class="btn btn-ghost btn-sm"
                                    @click="resumeImport(i)"
                                >
                                    {{ ['validated', 'compared'].includes(i.status) ? 'Ver revisión' : 'Ver avance' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<style scoped>
.backups { display: flex; flex-direction: column; gap: 1rem; }

.panel { padding: 1.1rem 1.25rem; }

.panel-head { display: flex; gap: 0.75rem; align-items: flex-start; margin-bottom: 1rem; }
.panel-head > svg { width: 1.4rem; height: 1.4rem; color: var(--color-primary); flex-shrink: 0; margin-top: 0.15rem; }
.panel-head h2, .panel-title { margin: 0 0 0.2rem; font-size: 1.02rem; }
.panel-head .hint { margin: 0; }

fieldset { border: none; margin: 0; padding: 0; }
legend { font-weight: 600; font-size: 0.84rem; margin-bottom: 0.35rem; padding: 0; }

.sections { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr)); gap: 0.4rem 1rem; }
.section-check { align-items: flex-start; }
.section-check small { display: block; font-size: 0.72rem; }

.period { display: flex; flex-wrap: wrap; gap: 0.4rem 1.25rem; margin-bottom: 0.5rem; }

.summary h3 { margin: 0 0 0.75rem; font-size: 0.98rem; }
.summary h4 { margin: 0 0 0.35rem; font-size: 0.84rem; }

.facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr)); gap: 0.5rem 1rem; margin: 0 0 1rem; }
.facts dt { font-size: 0.72rem; color: var(--color-text-muted); }
.facts dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }

.counts { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr)); gap: 0.75rem; margin-bottom: 0.75rem; }
.count-group { padding: 0.6rem 0.75rem; border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-surface-alt); }
.count-group ul { list-style: none; margin: 0; padding: 0; font-size: 0.8rem; }
.count-group li { display: flex; justify-content: space-between; gap: 0.5rem; padding: 0.12rem 0; }
.count-group strong { font-variant-numeric: tabular-nums; }

.notice { display: flex; gap: 0.6rem; align-items: flex-start; padding: 0.65rem 0.8rem; margin: 0.25rem 0 1rem; border-radius: var(--radius-sm); background: var(--color-warning-soft); font-size: 0.82rem; }
.notice svg { width: 1.1rem; height: 1.1rem; flex-shrink: 0; color: var(--color-warning); margin-top: 0.1rem; }
.notice p { margin: 0; }

.verified { display: flex; gap: 0.4rem; align-items: center; margin: -0.25rem 0 0.75rem; font-size: 0.8rem; color: var(--color-success); }
.verified svg { width: 1rem; height: 1rem; }

.new-company { margin-top: 0.5rem !important; }

.count-group li > span { min-width: 0; }
.count-group small { font-size: 0.72rem; }

.destination { margin: 0.25rem 0 0.75rem; }

.conflicts { margin-bottom: 1rem; }
.conflicts p { margin: 0 0 0.35rem; }
.conflicts p:last-child { margin: 0.35rem 0 0; }
.conflicts ul { margin: 0; padding-left: 1.2rem; }

.importing { display: flex; gap: 0.85rem; align-items: center; padding: 0.5rem 0; }
.importing p { margin: 0; }
.importing-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.45rem; }

.bar { height: 0.45rem; border-radius: 999px; background: var(--color-border); overflow: hidden; }
.bar > span { display: block; height: 100%; border-radius: inherit; background: var(--color-primary); transition: width 0.4s ease; }
.spinner { width: 1.6rem; height: 1.6rem; border: 3px solid var(--color-border); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.9s linear infinite; flex-shrink: 0; }

.done .flash { display: flex; gap: 0.5rem; align-items: center; }
.done .flash svg { width: 1.1rem; height: 1.1rem; flex-shrink: 0; }
.result { margin: 0.5rem 0 0.75rem; padding-left: 1.2rem; font-size: 0.84rem; }

.row-action { text-align: right; white-space: nowrap; }

@keyframes spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
    .spinner { animation: none; }
    .bar > span { transition: none; }
}
</style>
