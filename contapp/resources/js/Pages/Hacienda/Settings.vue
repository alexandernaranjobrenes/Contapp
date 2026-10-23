<script setup>
/**
 * Administración → Facturación electrónica (CLAUDE.md secc. 38). Solo el
 * Superusuario de la compañía: el servidor lo revisa en cada acción.
 *
 * Nada secreto llega a esta pantalla: de la contraseña del ATV, el PIN y la
 * llave solo se sabe si están cargados. Para cambiarlos se escriben de nuevo.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import TerritorySelects from '../../Components/TerritorySelects.vue';
import { confirmAction } from '../../Utils/confirm';
import {
    CheckIcon, PlugZapIcon, PlusIcon, PowerIcon, PowerOffIcon, TriangleAlertIcon, UploadIcon, XIcon,
} from '@lucide/vue';

const props = defineProps({
    company: { type: Object, required: true },
    settings: { type: Object, required: true },
    credentials: { type: Object, default: () => ({}) },
    missing: { type: Object, default: () => ({}) },
    series: { type: Array, default: () => [] },
    activity: { type: Object, default: null },
    systemProvider: { type: String, default: null },
    counts: { type: Object, default: () => ({}) },
    catalogs: { type: Object, required: true },
});

const environments = computed(() => Object.keys(props.catalogs.environments));
const activeMissing = computed(() => props.missing[props.settings.environment] ?? []);

// --- Encender, apagar y ambiente ---

function enable() {
    confirmAction({
        title: 'Encender la facturación electrónica',
        message: `Desde ahora, cada comprobante que se emita en ${props.company.legal_name} se firma con la llave de la compañía `
            + `y se envía a Hacienda (${props.catalogs.environments[props.settings.environment]}). Un comprobante enviado no se puede borrar: `
            + 'se corrige con una nota de crédito o débito.',
        confirmLabel: 'Encender',
        onConfirm: () => router.post(route('hacienda-settings.enable'), {}, { preserveScroll: true }),
    });
}

function disable() {
    confirmAction({
        title: 'Apagar la facturación electrónica',
        message: 'Los comprobantes nuevos se van a registrar en el ERP sin firmarse ni enviarse a Hacienda. '
            + 'Los que ya estaban en camino siguen hasta que Hacienda responda.',
        confirmLabel: 'Apagar',
        danger: true,
        onConfirm: () => router.post(route('hacienda-settings.disable'), {}, { preserveScroll: true }),
    });
}

const environmentChoice = ref(props.settings.environment);

function changeEnvironment() {
    const label = props.catalogs.environments[environmentChoice.value];

    confirmAction({
        title: `Pasar a ${label}`,
        message: environmentChoice.value === 'prod'
            ? 'En producción cada comprobante es real: tiene validez fiscal y se le reporta a Hacienda. Rige desde el próximo comprobante.'
            : 'En pruebas los comprobantes se envían al sandbox de Hacienda y no tienen validez fiscal. Rige desde el próximo comprobante.',
        confirmLabel: `Pasar a ${label}`,
        danger: environmentChoice.value === 'prod',
        onConfirm: () => router.put(route('hacienda-settings.environment'), { environment: environmentChoice.value }, { preserveScroll: true }),
    });
}

// --- Emisor ---

const emitter = useForm({
    identification_type: props.settings.identification_type ?? '',
    province: props.settings.province ?? '',
    canton: props.settings.canton ?? '',
    district: props.settings.district ?? '',
    neighborhood: props.settings.neighborhood ?? '',
    address_details: props.settings.address_details ?? '',
    phone: props.settings.phone ?? '',
    emails: [...(props.settings.emails?.length ? props.settings.emails : [''])],
    branch: props.settings.branch ?? '001',
    terminal: props.settings.terminal ?? '00001',
    send_to_customer: props.settings.send_to_customer ?? true,
});

function saveEmitter() {
    emitter
        .transform((data) => ({
            ...data,
            emails: data.emails.map((e) => e.trim()).filter(Boolean),
            neighborhood: data.neighborhood === '' ? null : data.neighborhood,
            phone: data.phone === '' ? null : data.phone,
        }))
        .put(route('hacienda-settings.emitter'), { preserveScroll: true });
}

// --- Credenciales y llave, por ambiente ---

const credentialForms = reactive(Object.fromEntries(environments.value.map((env) => [env, useForm({
    username: props.credentials[env]?.username ?? '',
    password: '',
})])));

const certificateForms = reactive(Object.fromEntries(environments.value.map((env) => [env, useForm({
    certificate: null,
    pin: '',
})])));

function saveCredentials(env) {
    credentialForms[env].put(route('hacienda-settings.credentials', env), {
        preserveScroll: true,
        // La contraseña no vuelve: el campo queda vacío otra vez.
        onSuccess: () => { credentialForms[env].password = ''; },
    });
}

function uploadCertificate(env) {
    certificateForms[env].post(route('hacienda-settings.certificate', env), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            certificateForms[env].certificate = null;
            certificateForms[env].pin = '';
        },
    });
}

function testConnection(env) {
    router.post(route('hacienda-settings.test', env), {}, { preserveScroll: true });
}

function date(value) {
    if (! value) return '—';

    return new Date(value).toLocaleDateString('es-CR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function dateTime(value) {
    if (! value) return '—';

    return new Date(value).toLocaleString('es-CR', { dateStyle: 'short', timeStyle: 'short' });
}

// --- Consecutivos ---

const sequenceForm = useForm({ branch: '', terminal: '', document_type: '01', next_number: 1 });
const editingSequence = ref(null); // 'new' | fila

function openSequence(row) {
    sequenceForm.clearErrors();
    sequenceForm.branch = row?.branch ?? props.settings.branch;
    sequenceForm.terminal = row?.terminal ?? props.settings.terminal;
    sequenceForm.document_type = row?.document_type ?? '01';
    sequenceForm.next_number = row?.next_number ?? 1;
    editingSequence.value = row ?? 'new';
}

function closeSequence() {
    editingSequence.value = null;
}

function saveSequence() {
    sequenceForm.put(route('hacienda-settings.sequence'), {
        preserveScroll: true,
        onSuccess: closeSequence,
    });
}
</script>

<template>
    <Head title="Facturación electrónica" />

    <AppLayout title="Facturación electrónica">
        <div class="view-toolbar">
            <div class="view-actions">
                <button v-if="settings.enabled" type="button" class="btn btn-ghost btn-danger-text" @click="disable">
                    <PowerOffIcon /> Apagar
                </button>
                <button v-else type="button" class="btn btn-primary" :disabled="activeMissing.length > 0" @click="enable">
                    <PowerIcon /> Encender
                </button>
            </div>
        </div>

        <!-- Estado -->
        <section class="card panel status" :class="{ pending: !settings.enabled }">
            <div class="status-head">
                <div>
                    <h2>{{ company.legal_name }}</h2>
                    <p class="muted small">
                        Cédula {{ company.tax_id || '—' }} · Ambiente:
                        <strong>{{ catalogs.environments[settings.environment] }}</strong>
                    </p>
                </div>
                <span class="badge" :class="settings.enabled ? 'badge-success' : 'badge-neutral'">
                    {{ settings.enabled ? `Encendida desde ${settings.enabled_at}` : 'Apagada' }}
                </span>
            </div>

            <div v-if="activeMissing.length" class="missing">
                <p class="missing-title"><TriangleAlertIcon /> Para emitir en {{ catalogs.environments[settings.environment] }} falta:</p>
                <ul>
                    <li v-for="item in activeMissing" :key="item">{{ item }}</li>
                </ul>
            </div>
            <p v-else class="ready"><CheckIcon /> Todo listo para emitir en {{ catalogs.environments[settings.environment] }}.</p>

            <div class="counts">
                <div><span class="muted small">En camino</span><strong>{{ counts.pending ?? 0 }}</strong></div>
                <div><span class="muted small">Aceptados</span><strong>{{ counts.accepted ?? 0 }}</strong></div>
                <div><span class="muted small">Rechazados</span><strong>{{ counts.rejected ?? 0 }}</strong></div>
                <div><span class="muted small">Requieren atención</span><strong>{{ counts.attention ?? 0 }}</strong></div>
            </div>

            <p class="hint">
                Proveedor de sistemas que va en cada comprobante:
                <strong>{{ systemProvider ?? `${company.tax_id} (la cédula de la compañía)` }}</strong>.
                <template v-if="!systemProvider">Si Pivox lo provee un tercero, se configura en <code>HACIENDA_SYSTEM_PROVIDER_ID</code>.</template>
            </p>
        </section>

        <!-- Ambiente -->
        <section class="card panel">
            <h2>Ambiente de Hacienda</h2>
            <p class="hint">
                Primero en pruebas (sandbox): los comprobantes se envían a Hacienda pero no tienen validez fiscal. Conviene
                probar en una compañía de prueba, porque cada comprobante igual queda registrado en el ERP. Cada ambiente usa
                sus propias credenciales y su propia llave.
            </p>
            <div class="form-grid">
                <div class="field">
                    <label for="environment">Ambiente</label>
                    <select id="environment" v-model="environmentChoice">
                        <option v-for="(label, env) in catalogs.environments" :key="env" :value="env">{{ label }}</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-primary" :disabled="environmentChoice === settings.environment" @click="changeEnvironment">
                    Cambiar ambiente
                </button>
            </div>
        </section>

        <!-- Emisor -->
        <section class="card panel">
            <h2>Datos del emisor</h2>
            <p class="hint">
                La razón social, el nombre comercial y la cédula son los de la compañía (Mi cuenta → Mis datos → Tus compañías).
                Acá va lo demás que el comprobante exige.
            </p>

            <form @submit.prevent="saveEmitter">
                <div class="form-grid">
                    <div class="field">
                        <label for="identification_type">Tipo de identificación</label>
                        <select id="identification_type" v-model="emitter.identification_type" required>
                            <option v-for="(label, code) in catalogs.identificationTypes" :key="code" :value="code">{{ label }}</option>
                        </select>
                        <span v-if="emitter.errors.identification_type" class="error">{{ emitter.errors.identification_type }}</span>
                    </div>

                    <TerritorySelects :form="emitter" :territory="catalogs.territory" required />

                    <div class="field">
                        <label for="neighborhood">Barrio (opcional)</label>
                        <input id="neighborhood" v-model="emitter.neighborhood" type="text" minlength="5" maxlength="50">
                        <span class="hint">De 5 a 50 caracteres; si no, va sin barrio.</span>
                        <span v-if="emitter.errors.neighborhood" class="error">{{ emitter.errors.neighborhood }}</span>
                    </div>

                    <div class="field span-full">
                        <label for="address_details">Otras señas</label>
                        <input id="address_details" v-model="emitter.address_details" type="text" minlength="5" maxlength="250" required>
                        <span v-if="emitter.errors.address_details" class="error">{{ emitter.errors.address_details }}</span>
                    </div>

                    <div class="field">
                        <label for="phone">Teléfono (opcional)</label>
                        <input id="phone" v-model="emitter.phone" type="tel" maxlength="20" placeholder="22223333">
                        <span v-if="emitter.errors.phone" class="error">{{ emitter.errors.phone }}</span>
                    </div>

                    <div class="field">
                        <label for="branch">Sucursal por defecto</label>
                        <input id="branch" v-model="emitter.branch" type="text" inputmode="numeric" maxlength="3" required>
                        <span v-if="emitter.errors.branch" class="error">{{ emitter.errors.branch }}</span>
                    </div>

                    <div class="field">
                        <label for="terminal">Terminal por defecto</label>
                        <input id="terminal" v-model="emitter.terminal" type="text" inputmode="numeric" maxlength="5" required>
                        <span class="hint">Si la compañía ya factura en TribuCR u otro sistema, usá otra terminal o seguí su numeración (Consecutivos, abajo).</span>
                        <span v-if="emitter.errors.terminal" class="error">{{ emitter.errors.terminal }}</span>
                    </div>

                    <div class="field span-full">
                        <span class="field-label">Correos del emisor (hasta 4)</span>
                        <div v-for="(email, index) in emitter.emails" :key="index" class="email-row">
                            <input v-model="emitter.emails[index]" type="email" maxlength="160" :aria-label="`Correo ${index + 1}`" :required="index === 0">
                            <button
                                v-if="emitter.emails.length > 1"
                                type="button"
                                class="btn btn-ghost"
                                :aria-label="`Quitar el correo ${index + 1}`"
                                @click="emitter.emails.splice(index, 1)"
                            ><XIcon /></button>
                            <span v-if="emitter.errors[`emails.${index}`]" class="error">{{ emitter.errors[`emails.${index}`] }}</span>
                        </div>
                        <button v-if="emitter.emails.length < 4" type="button" class="btn btn-ghost add-email" @click="emitter.emails.push('')">
                            <PlusIcon /> Correo
                        </button>
                        <span v-if="emitter.errors.emails" class="error">{{ emitter.errors.emails }}</span>
                    </div>

                    <label class="check span-full">
                        <input v-model="emitter.send_to_customer" type="checkbox">
                        Enviar al cliente el XML, la respuesta de Hacienda y el PDF cuando Hacienda acepta el comprobante
                    </label>
                </div>

                <p v-if="activity" class="hint">
                    Actividad económica por defecto: <strong>{{ activity.code }} — {{ activity.name }}</strong>
                    <span v-if="!activity.valid" class="warn">· tiene que ir con el formato de la v4.4 (6201.0): cambiala en Facturación → Parámetros de facturación.</span>
                </p>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" :disabled="emitter.processing">Guardar datos del emisor</button>
                </div>
            </form>
        </section>

        <!-- Credenciales por ambiente -->
        <div class="credentials-grid">
            <section v-for="env in environments" :key="env" class="card panel">
                <h2>{{ catalogs.environments[env] }}</h2>
                <p class="hint">
                    El usuario y la contraseña del API, y la llave criptográfica (.p12) con su PIN, se generan en el ATV de
                    Hacienda {{ env === 'stag' ? 'para el ambiente de pruebas' : 'para producción' }}.
                </p>

                <h3>Usuario del API del ATV</h3>
                <form @submit.prevent="saveCredentials(env)">
                    <div class="form-grid">
                        <div class="field">
                            <label :for="`username-${env}`">Usuario</label>
                            <input :id="`username-${env}`" v-model="credentialForms[env].username" type="text" autocomplete="off" maxlength="160" required>
                            <span v-if="credentialForms[env].errors.username" class="error">{{ credentialForms[env].errors.username }}</span>
                        </div>
                        <div class="field">
                            <label :for="`password-${env}`">Contraseña</label>
                            <input
                                :id="`password-${env}`"
                                v-model="credentialForms[env].password"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="credentials[env]?.has_password ? 'Guardada · escribila para cambiarla' : ''"
                                :required="!credentials[env]?.has_password"
                            >
                            <span v-if="credentialForms[env].errors.password" class="error">{{ credentialForms[env].errors.password }}</span>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button
                            type="button"
                            class="btn btn-ghost"
                            :disabled="!credentials[env]?.has_password"
                            title="Pide un token a Hacienda con este usuario y contraseña. No envía ningún comprobante."
                            @click="testConnection(env)"
                        ><PlugZapIcon /> Probar conexión</button>
                        <button type="submit" class="btn btn-primary" :disabled="credentialForms[env].processing">Guardar</button>
                    </div>
                    <p v-if="credentials[env]?.last_tested_at" class="hint" :class="credentials[env].last_test_ok ? 'ok' : 'warn'">
                        Última prueba {{ dateTime(credentials[env].last_tested_at) }}: {{ credentials[env].last_test_message }}
                    </p>
                </form>

                <h3>Llave criptográfica</h3>
                <dl v-if="credentials[env]?.has_certificate" class="detail-list">
                    <div><dt>Titular</dt><dd>{{ credentials[env].certificate_subject }}</dd></div>
                    <div><dt>Cédula del titular</dt><dd>{{ credentials[env].certificate_holder_id ?? '—' }}</dd></div>
                    <div><dt>Emisor</dt><dd>{{ credentials[env].certificate_issuer }}</dd></div>
                    <div>
                        <dt>Vigencia</dt>
                        <dd>
                            {{ date(credentials[env].certificate_valid_from) }} al {{ date(credentials[env].certificate_valid_to) }}
                            <span v-if="credentials[env].certificate_expired" class="badge badge-danger">Vencida</span>
                        </dd>
                    </div>
                    <div><dt>Cargada</dt><dd>{{ dateTime(credentials[env].certificate_uploaded_at) }}</dd></div>
                </dl>
                <p v-else class="muted small">Sin llave cargada.</p>

                <form @submit.prevent="uploadCertificate(env)">
                    <div class="form-grid">
                        <div class="field">
                            <label :for="`certificate-${env}`">Archivo .p12</label>
                            <input
                                :id="`certificate-${env}`"
                                type="file"
                                accept=".p12,.pfx"
                                required
                                @change="certificateForms[env].certificate = $event.target.files[0] ?? null"
                            >
                            <span v-if="certificateForms[env].errors.certificate" class="error">{{ certificateForms[env].errors.certificate }}</span>
                        </div>
                        <div class="field">
                            <label :for="`pin-${env}`">PIN</label>
                            <input :id="`pin-${env}`" v-model="certificateForms[env].pin" type="password" autocomplete="off" inputmode="numeric" maxlength="64" required>
                            <span v-if="certificateForms[env].errors.pin" class="error">{{ certificateForms[env].errors.pin }}</span>
                        </div>
                    </div>
                    <p class="hint">Se revisa al cargarla: el PIN, la vigencia y que sea de esta compañía. Se guarda cifrada.</p>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" :disabled="certificateForms[env].processing">
                            <UploadIcon /> {{ credentials[env]?.has_certificate ? 'Reemplazar llave' : 'Cargar llave' }}
                        </button>
                    </div>
                </form>

                <div v-if="(missing[env] ?? []).length" class="missing compact">
                    <p class="missing-title"><TriangleAlertIcon /> Para emitir en este ambiente falta:</p>
                    <ul><li v-for="item in missing[env]" :key="item">{{ item }}</li></ul>
                </div>
            </section>
        </div>

        <!-- Consecutivos -->
        <section class="card panel">
            <div class="panel-head">
                <div>
                    <h2>Consecutivos</h2>
                    <p class="hint">
                        Cada tipo de comprobante lleva su propia numeración por sucursal y terminal. Para seguir la que traía la
                        compañía de TribuCR o de otro sistema, fijá el próximo número; nunca puede quedar detrás de uno ya usado.
                    </p>
                </div>
                <button type="button" class="btn btn-primary" @click="openSequence(null)"><PlusIcon /> Crear nuevo</button>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Tipo</th><th>Sucursal · terminal</th><th class="num">Próximo número</th></tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in series"
                            :key="`${row.branch}-${row.terminal}-${row.document_type}`"
                            class="clickable-row"
                            tabindex="0"
                            @click="openSequence(row)"
                            @keydown.enter="openSequence(row)"
                            @keydown.space.prevent="openSequence(row)"
                        >
                            <td>{{ row.document_type }} — {{ row.label }}</td>
                            <td data-label="Sucursal · terminal" class="code-cell">{{ row.branch }} · {{ row.terminal }}</td>
                            <td data-label="Próximo número" class="num">{{ row.next_number }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <DetailModal
            :open="editingSequence !== null"
            :title="editingSequence === 'new' ? 'Nueva serie de consecutivos' : 'Próximo número'"
            @close="closeSequence"
        >
            <form id="sequence-form" @submit.prevent="saveSequence">
                <div class="form-grid">
                    <div class="field">
                        <label for="sequence-type">Tipo de comprobante</label>
                        <select id="sequence-type" v-model="sequenceForm.document_type" :disabled="editingSequence !== 'new'">
                            <option v-for="(label, code) in catalogs.documentTypes" :key="code" :value="code">{{ code }} — {{ label }}</option>
                        </select>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="sequence-branch">Sucursal</label>
                            <input id="sequence-branch" v-model="sequenceForm.branch" type="text" inputmode="numeric" maxlength="3" :disabled="editingSequence !== 'new'" required>
                            <span v-if="sequenceForm.errors.branch" class="error">{{ sequenceForm.errors.branch }}</span>
                        </div>
                        <div class="field">
                            <label for="sequence-terminal">Terminal</label>
                            <input id="sequence-terminal" v-model="sequenceForm.terminal" type="text" inputmode="numeric" maxlength="5" :disabled="editingSequence !== 'new'" required>
                            <span v-if="sequenceForm.errors.terminal" class="error">{{ sequenceForm.errors.terminal }}</span>
                        </div>
                    </div>
                    <div class="field">
                        <label for="sequence-next">Próximo número</label>
                        <input id="sequence-next" v-model="sequenceForm.next_number" type="number" min="1" max="9999999999" required>
                        <span v-if="sequenceForm.errors.next_number" class="error">{{ sequenceForm.errors.next_number }}</span>
                    </div>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="closeSequence">Cancelar</button>
                <button type="submit" form="sequence-form" class="btn btn-primary" :disabled="sequenceForm.processing">Guardar</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.panel { padding: 1rem 1.25rem; margin-bottom: 0.9rem; }
.panel h2 { font-size: 1rem; margin: 0 0 0.3rem; }
.panel h3 { font-size: 0.86rem; margin: 1rem 0 0.4rem; color: var(--color-text-muted); }
.panel-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.75rem; }
.panel-head > div { flex: 1 1 20rem; min-width: 0; }
.hint { font-size: 0.78rem; color: var(--color-text-muted); margin: 0.3rem 0 0.6rem; }
.hint.ok { color: var(--color-success); }
.hint.warn, .warn { color: var(--color-warning); }

.status.pending { border-left: 3px solid var(--color-warning); }
.status-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
.missing { margin: 0.6rem 0; padding: 0.6rem 0.8rem; border-radius: var(--radius-md, 8px); background: var(--color-warning-soft); }
.missing.compact { margin-top: 1rem; }
.missing-title { display: flex; align-items: center; gap: 0.35rem; margin: 0 0 0.3rem; font-weight: 600; color: var(--color-warning); font-size: 0.82rem; }
.missing ul { margin: 0; padding-left: 1.2rem; font-size: 0.8rem; }
.ready { display: flex; align-items: center; gap: 0.35rem; color: var(--color-success); font-weight: 600; font-size: 0.85rem; margin: 0.6rem 0; }
.counts { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr)); gap: 0.6rem; margin: 0.6rem 0; }
.counts > div { display: flex; flex-direction: column; gap: 0.1rem; }
.counts strong { font-size: 1.15rem; font-variant-numeric: tabular-nums; }

.credentials-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr)); gap: 0 0.9rem; }
.field-label { font-size: 0.82rem; font-weight: 600; }
.email-row { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; margin-bottom: 0.35rem; }
.email-row input { flex: 1 1 14rem; min-width: 0; }
.email-row .error { flex-basis: 100%; }
.add-email { align-self: flex-start; }

table { font-size: 0.85rem; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.num { text-align: right; }

@media (max-width: 640px) {
    .panel-head .btn { width: 100%; justify-content: center; }
}
</style>
