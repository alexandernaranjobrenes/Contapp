<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArchiveRestoreIcon, DownloadIcon, LockKeyholeIcon, ShieldCheckIcon, UploadIcon } from '@lucide/vue';
import { requestJson } from '../Utils/http';
import { fullDate } from '../Utils/relativeTime';

/**
 * Exportar e importar el respaldo de una compañía (CLAUDE.md secc. 37). Lo
 * usan Administración → Respaldos (el Superusuario) y el backoffice: cambian
 * las rutas, a quién se le pide la contraseña y si se puede ir a la compañía.
 *
 * Exportar: qué y de qué período → el resumen («¿estás seguro?») → la
 * contraseña del archivo y la de la cuenta → baja el archivo.
 * Importar: el archivo y su contraseña → lo que trae, ya verificado → los
 * datos de la compañía nueva y la contraseña de la cuenta → en cola, y se
 * sigue el avance.
 */
const props = defineProps({
    companies: { type: Array, required: true },
    currentCompanyId: { type: Number, default: null },
    sections: { type: Array, required: true },
    license: { type: Object, required: true },
    minPassword: { type: Number, default: 10 },
    imports: { type: Array, default: () => [] },
    // { preview, export, import, confirm, status }: nombres de ruta; params, los que llevan todas.
    routes: { type: Object, required: true },
    routeParams: { type: Object, default: () => ({}) },
    accountLabel: { type: String, default: 'Tu contraseña de CONTAPP' },
    backoffice: { type: Boolean, default: false },
});

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

const importStep = ref('upload'); // upload | confirm | importing | done | failed
const importFile = ref(null);
const fileInput = ref(null);
const importPassword = ref('');
const importErrors = ref({});
const importBusy = ref(false);
const pending = ref(null); // { uuid, summary, defaults, can_add_company }
const companyForm = ref({ legal_name: '', trade_name: '', tax_id: '', account_password: '' });
const status = ref(null);
let poll = null;

function pickFile(event) {
    importFile.value = event.target.files?.[0] ?? null;
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

    pending.value = result.data;
    companyForm.value = { ...result.data.defaults, trade_name: result.data.defaults.trade_name ?? '', tax_id: result.data.defaults.tax_id ?? '', account_password: '' };
    importPassword.value = '';
    importStep.value = 'confirm';
}

async function confirmImport() {
    importBusy.value = true;
    importErrors.value = {};
    const result = await requestJson(url('confirm', { uuid: pending.value.uuid }), { method: 'POST', body: companyForm.value });
    importBusy.value = false;

    if (! result.ok) {
        importErrors.value = result.status === 422 ? result.errors : { general: result.message };
        return;
    }

    status.value = result.data;
    importStep.value = 'importing';
    watchStatus();
}

function watchStatus() {
    clearInterval(poll);
    poll = setInterval(async () => {
        const result = await requestJson(url('status', { uuid: pending.value.uuid }));

        if (! result.ok) return;

        status.value = result.data;

        if (result.data.status === 'done' || result.data.status === 'failed') {
            clearInterval(poll);
            importStep.value = result.data.status;
            router.reload({ only: ['imports', 'license'] });
        }
    }, 2000);
}

onBeforeUnmount(() => clearInterval(poll));

function restartImport() {
    importStep.value = 'upload';
    pending.value = null;
    status.value = null;
    importFile.value = null;
    importErrors.value = {};

    if (fileInput.value) fileInput.value.value = '';
}

function goToCompany(companyId) {
    router.put(route('company-switch'), { company_id: companyId });
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
    validated: ['Esperando confirmación', 'badge-neutral'],
    queued: ['En cola', 'badge-neutral'],
    importing: ['Importando', 'badge-warning'],
    done: ['Importado', 'badge-success'],
    failed: ['Con error', 'badge-danger'],
    expired: ['Vencido', 'badge-neutral'],
};

const droppedTotal = computed(() => Object.values(status.value?.result?.dropped ?? {}).reduce((a, b) => a + b, 0));

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
        <section class="card panel" aria-labelledby="import-title">
            <header class="panel-head">
                <ArchiveRestoreIcon aria-hidden="true" />
                <div>
                    <h2 id="import-title">Importar un respaldo</h2>
                    <p class="hint">Crea una compañía nueva en la licencia con lo que trae el archivo. Nunca se mezcla con una que ya existe.</p>
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
                        <UploadIcon /> {{ importBusy ? 'Revisando el archivo…' : 'Revisar' }}
                    </button>
                </div>
            </form>

            <form v-else-if="importStep === 'confirm'" class="summary" @submit.prevent="confirmImport">
                <h3>¿Estás seguro de importar este respaldo?</h3>
                <p class="verified"><ShieldCheckIcon aria-hidden="true" /> Lo generó esta instalación de CONTAPP y no fue modificado.</p>
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
                    Las personas entran por su correo, si ya tienen cuenta en CONTAPP y caben en la licencia. Lo contabilizado entra contabilizado,
                    con los mismos números de documento.
                </p>

                <p v-if="importErrors.general" class="flash flash-error" role="alert">{{ importErrors.general }}</p>

                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" :disabled="importBusy" @click="restartImport">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="importBusy || ! pending.can_add_company" :data-busy="importBusy ? '' : null">
                        <ArchiveRestoreIcon /> {{ importBusy ? 'Enviando…' : 'Sí, importar' }}
                    </button>
                </div>
            </form>

            <div v-else-if="importStep === 'importing'" class="importing" role="status" aria-live="polite">
                <span class="spinner" aria-hidden="true"></span>
                <p>
                    <strong>Importando «{{ companyForm.trade_name || companyForm.legal_name }}»…</strong><br>
                    <span class="muted">Puede tardar unos minutos. Podés salir de esta pantalla: sigue solo.</span>
                </p>
            </div>

            <div v-else-if="importStep === 'done'" class="done">
                <p class="flash flash-success" role="status">
                    <ShieldCheckIcon aria-hidden="true" />
                    Listo: se creó «{{ status.company_name }}» con {{ number(status.result?.imported) }} registros.
                </p>
                <ul class="result">
                    <li v-if="status.result?.people?.added?.length">Con acceso: {{ status.result.people.added.join(', ') }}.</li>
                    <li v-if="status.result?.people?.missing?.length">
                        Sin cuenta en CONTAPP (invitalas desde Usuarios): {{ status.result.people.missing.join(', ') }}.
                    </li>
                    <li v-if="status.result?.people?.no_quota?.length">
                        No cupieron en la licencia: {{ status.result.people.no_quota.join(', ') }}.
                    </li>
                    <li v-if="droppedTotal">
                        Quedaron fuera {{ number(droppedTotal) }} registros que dependían de movimientos anteriores al período.
                    </li>
                </ul>
                <div class="form-actions">
                    <button type="button" class="btn btn-ghost" @click="restartImport">Importar otro</button>
                    <button v-if="! backoffice" type="button" class="btn btn-primary" @click="goToCompany(status.company_id)">Ir a la compañía</button>
                </div>
            </div>

            <div v-else class="done">
                <p class="flash flash-error" role="alert">{{ status?.error ?? 'No se pudo importar.' }}</p>
                <button type="button" class="btn btn-ghost" @click="restartImport">Volver a intentar</button>
            </div>
        </section>

        <!-- ── Importaciones recientes ── -->
        <section v-if="imports.length" class="card panel" aria-labelledby="imports-title">
            <h2 id="imports-title" class="panel-title">Importaciones recientes</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Fecha</th><th>Respaldo de</th><th>Período</th><th>Estado</th><th>Compañía creada</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in imports" :key="i.uuid">
                            <td data-label="Fecha">{{ fullDate(i.created_at) }}</td>
                            <td data-label="Respaldo de">{{ i.source }}</td>
                            <td data-label="Período">{{ i.period }}</td>
                            <td data-label="Estado"><span class="badge" :class="STATUS[i.status]?.[1]">{{ STATUS[i.status]?.[0] ?? i.status }}</span></td>
                            <td data-label="Compañía creada">{{ i.status === 'done' ? i.company_name : (i.error ?? '—') }}</td>
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

.importing { display: flex; gap: 0.85rem; align-items: center; padding: 0.5rem 0; }
.importing p { margin: 0; }
.spinner { width: 1.6rem; height: 1.6rem; border: 3px solid var(--color-border); border-top-color: var(--color-primary); border-radius: 50%; animation: spin 0.9s linear infinite; flex-shrink: 0; }

.done .flash { display: flex; gap: 0.5rem; align-items: center; }
.done .flash svg { width: 1.1rem; height: 1.1rem; flex-shrink: 0; }
.result { margin: 0.5rem 0 0.75rem; padding-left: 1.2rem; font-size: 0.84rem; }

@keyframes spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
    .spinner { animation: none; }
}
</style>
