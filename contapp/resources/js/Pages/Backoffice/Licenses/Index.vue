<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import LicenseAssignmentFields from '../../../Components/LicenseAssignmentFields.vue';
import { confirmAction } from '../../../Utils/confirm';
import { CheckIcon, ChevronRightIcon, MailIcon, PencilIcon, PlusIcon, UserPlusIcon, XIcon } from '@lucide/vue';

const props = defineProps({
    licenses: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});

// Alta (CLAUDE.md secc. 21): el botón «Crear nuevo», arriba de la tabla,
// abre el mismo modal de la ficha en modo 'create', con el formulario de
// emisión.
const issueForm = useForm({
    category_id: '',
    expires_at: '',
    notes: '',
    // A quién se asigna de una vez (LicenseAssignmentFields). 'none': como
    // siempre, se entrega el código.
    assign_to: 'none',
    assign_email: '',
    assign_name: '',
});

function openCreate() {
    issueForm.reset();
    issueForm.clearErrors();
    selectedId.value = null;
    mode.value = 'create';
}

function onCategoryChange() {
    const category = props.categories.find((c) => c.id === Number(issueForm.category_id));
    if (category && !issueForm.expires_at) {
        const date = new Date();
        date.setMonth(date.getMonth() + category.duration_months);
        issueForm.expires_at = date.toISOString().slice(0, 10);
    }
}

function issue() {
    issueForm.post(route('backoffice.licenses.store'), {
        // Al cerrarse el modal queda a la vista el mensaje con la clave
        // completa, que es la que se le entrega al cliente.
        onSuccess: () => {
            issueForm.reset();
            closeLicense();
        },
    });
}

// Ficha de la licencia (CLAUDE.md secc. 20): la tabla muestra solo lo
// necesario para ubicar una licencia, y su fila abre este modal con el
// detalle completo y todas las acciones.
const selectedId = ref(null);
const mode = ref('details'); // 'create' | 'details' | 'edit' | 'renew' | 'assign'

// Se busca en props cada vez, no se guarda una copia: después de renovar o
// suspender, Inertia recarga `licenses` y la ficha abierta muestra el dato
// nuevo sin cerrarse.
const selected = computed(() => props.licenses.find((l) => l.id === selectedId.value) ?? null);

const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => (mode.value === 'create' ? 'Emitir nueva licencia' : selected.value?.masked_code ?? ''));

function openLicense(license) {
    selectedId.value = license.id;
    mode.value = 'details';
}

function closeLicense() {
    // Con la confirmación de suspender/revocar abierta encima, Escape cierra
    // primero esa confirmación, no la ficha que está debajo.
    if (pendingAction.value) {
        pendingAction.value = null;
        return;
    }

    selectedId.value = null;
    mode.value = 'details';
}

// "Ver código completo" (CLAUDE.md secc. 13): la clave no viaja en los datos
// de la página; se pide al servidor solo cuando el Propietario la quiere ver,
// y se olvida al cerrar la ficha o pasar a otra licencia.
const revealedCode = ref(null);
const revealing = ref(false);
const revealError = ref('');
const copied = ref(false);
const copyHint = ref('');
const codeEl = ref(null);

watch(selectedId, () => {
    revealedCode.value = null;
    revealError.value = '';
    copied.value = false;
    copyHint.value = '';
});

async function revealCode() {
    const licenseId = selectedId.value;
    revealing.value = true;
    revealError.value = '';

    try {
        const res = await fetch(route('backoffice.licenses.code', licenseId), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        // Con la sesión vencida el servidor no responde 401 sino que redirige
        // al login (bootstrap/app.php), y fetch sigue la redirección.
        if (res.redirected) throw new Error('session');
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const { code } = await res.json();

        // Si mientras tanto se cerró la ficha o se abrió otra licencia, la
        // clave que llegó ya no es la de la ficha en pantalla.
        if (selectedId.value === licenseId) {
            revealedCode.value = code;
            refreshHistory();
        }
    } catch (error) {
        if (selectedId.value === licenseId) {
            revealError.value = error.message === 'session'
                ? 'Tu sesión venció: recargá la página para volver a entrar.'
                : 'No se pudo obtener el código. Intentá de nuevo.';
        }
    } finally {
        revealing.value = false;
    }
}

function hideCode() {
    revealedCode.value = null;
    copied.value = false;
    copyHint.value = '';
}

async function copyCode() {
    try {
        await navigator.clipboard.writeText(revealedCode.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        // Sin acceso al portapapeles (el navegador lo bloquea fuera de
        // HTTPS): el código queda seleccionado para copiarlo a mano.
        window.getSelection()?.selectAllChildren(codeEl.value);
        copyHint.value = 'El código quedó seleccionado: copialo con Ctrl+C.';
    }
}

// Historial de la licencia (la bitácora del Propietario): se pide al abrir
// la sección, no viaja en el listado. Abierto, se refresca solo después de
// cada acción de la ficha, que agrega un movimiento.
const historyOpen = ref(false);
const historyLoading = ref(false);
const historyError = ref('');
const history = ref([]);
const historyHasMore = ref(false);

watch(selectedId, () => {
    historyOpen.value = false;
    historyError.value = '';
    history.value = [];
    historyHasMore.value = false;
});

function toggleHistory() {
    historyOpen.value = !historyOpen.value;
    if (historyOpen.value) loadHistory();
}

function refreshHistory() {
    if (historyOpen.value) loadHistory();
}

async function loadHistory() {
    const licenseId = selectedId.value;
    historyLoading.value = true;
    historyError.value = '';

    try {
        const res = await fetch(route('backoffice.licenses.history', licenseId), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.redirected) throw new Error('session');
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        if (selectedId.value === licenseId) {
            history.value = data.entries;
            historyHasMore.value = data.has_more;
        }
    } catch (error) {
        if (selectedId.value === licenseId) {
            historyError.value = error.message === 'session'
                ? 'Tu sesión venció: recargá la página para volver a entrar.'
                : 'No se pudo cargar el historial. Intentá de nuevo.';
        }
    } finally {
        historyLoading.value = false;
    }
}

const HISTORY_ACTIONS = {
    license_issued: 'Emitió la licencia',
    license_updated: 'Editó la licencia',
    license_renewed: 'Renovó la licencia',
    license_suspended: 'Suspendió la licencia',
    license_reactivated: 'Reactivó la licencia',
    license_revoked: 'Revocó la licencia',
    license_code_revealed: 'Vio la clave completa',
    license_invitation_assigned: 'Asignó la licencia',
    license_invitation_updated: 'Cambió a quién está asignada',
    license_invitation_sent: 'Envió el correo para aceptarla',
    license_invitation_resent: 'Reenvió el correo para aceptarla',
    license_invitation_cancelled: 'Quitó la asignación',
    license_invitation_accepted: 'La persona aceptó la licencia',
    license_activated: 'Se activó con una cuenta existente',
};

const HISTORY_FIELDS = {
    category_id: 'Categoría',
    max_companies: 'Empresas máx.',
    max_admins: 'Admins máx.',
    max_users: 'Usuarios máx.',
    expires_at: 'Vence',
    status: 'Estado',
    notes: 'Notas',
    account_type: 'Cuenta',
    email: 'Correo',
    name: 'Nombre',
    company: 'Compañía',
};

const STATUS_WORDS = { active: 'activa', suspended: 'suspendida', revoked: 'revocada' };

const ACCOUNT_WORDS = { existing: 'existente', new: 'nueva' };

function historyValue(field, value) {
    if (value === null || value === '') return '—';
    if (field === 'status') return STATUS_WORDS[value] ?? value;
    if (field === 'account_type') return ACCOUNT_WORDS[value] ?? value;
    return value;
}

// Al emitir no hay "antes": se muestran los datos con que nació. En el resto
// se omite lo que no cambió (renovar guarda el estado aunque siga igual).
const SNAPSHOT_ACTIONS = ['license_issued', 'license_invitation_assigned', 'license_invitation_sent', 'license_invitation_resent', 'license_invitation_accepted', 'license_activated'];

function historyChanges(entry) {
    return SNAPSHOT_ACTIONS.includes(entry.action)
        ? entry.changes.filter((change) => change.to !== null)
        : entry.changes.filter((change) => change.from !== change.to);
}

// La base guarda UTC; se muestra en la hora local de quien mira, con el
// mismo formato de fecha del resto del sistema (año-mes-día).
const historyClock = new Intl.DateTimeFormat('es-CR', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
});

function historyWhen(iso) {
    const parts = Object.fromEntries(historyClock.formatToParts(new Date(iso)).map((part) => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`;
}

const editForm = useForm({
    category_id: '', max_companies: 1, max_admins: 3, max_users: 10, notes: '',
});

function startEdit() {
    const license = selected.value;
    editForm.clearErrors();
    editForm.category_id = license.category_id;
    editForm.max_companies = license.max_companies;
    editForm.max_admins = license.max_admins;
    editForm.max_users = license.max_users;
    editForm.notes = license.notes ?? '';
    mode.value = 'edit';
}

function saveEdit() {
    editForm.put(route('backoffice.licenses.update', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            mode.value = 'details';
            refreshHistory();
        },
    });
}

const renewForm = useForm({ expires_at: '' });

function startRenew() {
    renewForm.reset();
    renewForm.clearErrors();
    mode.value = 'renew';
}

function saveRenew() {
    renewForm.post(route('backoffice.licenses.renew', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            mode.value = 'details';
            refreshHistory();
        },
    });
}

function reactivate() {
    router.post(route('backoffice.licenses.reactivate', selected.value.id), {}, {
        preserveScroll: true,
        onSuccess: refreshHistory,
    });
}

// Suspender/revocar son cortes administrativos reales sobre el acceso de un
// cliente — confirmación explícita antes de dispararlos, no un solo clic.
const pendingAction = ref(null); // { license, action: 'suspend'|'revoke' }
const confirmProcessing = ref(false);

const ACTION_COPY = {
    suspend: { title: 'Suspender licencia', confirmLabel: 'Suspender', message: (l) => `${l.masked_code} y todas sus compañías quedan sin acceso hasta que la reactivés. Es reversible.` },
    revoke: { title: 'Revocar licencia', confirmLabel: 'Revocar', message: (l) => `${l.masked_code} queda dada de baja de forma definitiva. A diferencia de suspender, esto no tiene vuelta atrás.` },
};

function askConfirm(action) {
    pendingAction.value = { license: selected.value, action };
}

function confirmPendingAction() {
    if (!pendingAction.value) return;
    const { license, action } = pendingAction.value;
    const routeName = action === 'suspend' ? 'backoffice.licenses.suspend' : 'backoffice.licenses.revoke';

    confirmProcessing.value = true;
    router.post(route(routeName, license.id), {}, {
        preserveScroll: true,
        onSuccess: refreshHistory,
        onFinish: () => {
            confirmProcessing.value = false;
            pendingAction.value = null;
        },
    });
}

// Asignar la licencia a una persona, o cambiar a quién está asignada, desde
// la ficha (LicenseInvitationService). Mientras no la acepte, se puede
// reenviar el correo, cambiar los datos o quitar la asignación.
const assignForm = useForm({ assign_to: 'existing', assign_email: '', assign_name: '' });

function startAssign() {
    const invitation = selected.value.invitation;
    assignForm.clearErrors();
    assignForm.assign_to = invitation?.account_type ?? 'existing';
    assignForm.assign_email = invitation?.email ?? '';
    assignForm.assign_name = invitation?.account_type === 'new' ? invitation.name : '';
    mode.value = 'assign';
}

function saveAssign() {
    assignForm.post(route('backoffice.licenses.invitation.store', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            mode.value = 'details';
            refreshHistory();
        },
    });
}

function resendInvitation() {
    router.post(route('backoffice.licenses.invitation.resend', selected.value.id), {}, {
        preserveScroll: true,
        onSuccess: refreshHistory,
    });
}

function cancelInvitation() {
    const license = selected.value;
    confirmAction({
        title: 'Quitar la asignación',
        message: `${license.invitation.name ?? license.invitation.email} ya no va a poder aceptar ${license.masked_code}: el enlace que se le envió deja de servir, y la licencia vuelve a activarse con su código.`,
        confirmLabel: 'Quitar asignación',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.licenses.invitation.destroy', license.id), {
            preserveScroll: true,
            onSuccess: refreshHistory,
        }),
    });
}

// ¿Se puede asignar? Sin dueño todavía y vigente.
function canAssign(license) {
    return !license.superuser && license.status === 'active' && license.display_status !== 'expired';
}

const INVITATION_STATUS = {
    pending: { label: 'Esperando que la acepte', cls: 'badge-warning' },
    expired: { label: 'Enlace vencido', cls: 'badge-danger' },
    accepted: { label: 'Aceptada', cls: 'badge-success' },
};

const clock = new Intl.DateTimeFormat('es-CR', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

function invitationTime(iso) {
    return iso ? `${historyWhen(iso).slice(0, 10)} a las ${clock.format(new Date(iso))}` : '—';
}

const STATUS_LABELS = {
    active: { label: 'Vigente', cls: 'badge-success' },
    expiring_soon: { label: 'Por vencer', cls: 'badge-warning' },
    expired: { label: 'Vencida', cls: 'badge-warning' },
    suspended: { label: 'Suspendida', cls: 'badge-danger' },
    revoked: { label: 'Revocada', cls: 'badge-danger' },
};

function statusOf(license) {
    return STATUS_LABELS[license.display_status] ?? { label: license.display_status, cls: 'badge' };
}
</script>

<template>
    <Head title="Licencias" />

    <BackofficeLayout title="Licencias">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <!-- "Próximo seguimiento" se queda en la tabla aunque la regla de la
             secc. 20 empuje a pasarlo a la ficha: la secc. 15 pide ver el
             estado de la licencia y la próxima acción comercial juntos, en
             el listado. -->
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Superusuario</th>
                            <th>Categoría</th>
                            <th>Vence</th>
                            <th>Estado</th>
                            <th>Próximo seguimiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="license in licenses"
                            :key="license.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openLicense(license)"
                            @keydown.enter="openLicense(license)"
                            @keydown.space.prevent="openLicense(license)"
                        >
                            <td class="code-cell">{{ license.masked_code }}</td>
                            <td data-label="Superusuario">
                                <template v-if="license.superuser">{{ license.superuser.name }}</template>
                                <!-- Asignada desde el backoffice, todavía sin aceptar. -->
                                <template v-else-if="license.invitation">
                                    {{ license.invitation.name ?? license.invitation.email }}
                                    <span class="badge" :class="INVITATION_STATUS[license.invitation.status].cls">
                                        {{ license.invitation.status === 'pending' ? 'Sin aceptar' : 'Enlace vencido' }}
                                    </span>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td data-label="Categoría">{{ license.category?.name ?? '—' }}</td>
                            <td data-label="Vence" class="date-cell">{{ license.expires_at }}</td>
                            <td data-label="Estado"><span class="badge" :class="statusOf(license).cls">{{ statusOf(license).label }}</span></td>
                            <td data-label="Próximo seguimiento">
                                <span v-if="license.next_pending_follow_up">
                                    {{ license.next_pending_follow_up.next_action_date }} — {{ license.next_pending_follow_up.action_type }}
                                </span>
                                <span v-else class="muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="!licenses.length">
                            <td colspan="6" class="muted empty-row">Todavía no se ha emitido ninguna licencia.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="closeLicense">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="statusOf(selected).cls">{{ statusOf(selected).label }}</span>
            </template>

            <form v-if="mode === 'create'" id="license-issue-form" @submit.prevent="issue">
                <div class="field">
                    <label for="issue-license-category">Categoría</label>
                    <select id="issue-license-category" v-model="issueForm.category_id" required @change="onCategoryChange">
                        <option value="" disabled>Elegir…</option>
                        <option v-for="category in categories" :key="category.id" :value="category.id">
                            {{ category.name }} ({{ category.max_companies }} empresas)
                        </option>
                    </select>
                    <span v-if="issueForm.errors.category_id" class="error">{{ issueForm.errors.category_id }}</span>
                </div>
                <div class="field">
                    <label for="issue-license-expires">Vence</label>
                    <input id="issue-license-expires" v-model="issueForm.expires_at" type="date" required>
                    <span v-if="issueForm.errors.expires_at" class="error">{{ issueForm.errors.expires_at }}</span>
                </div>
                <div class="field">
                    <label for="issue-license-notes">Notas (cliente, convenio…)</label>
                    <input id="issue-license-notes" v-model="issueForm.notes" type="text">
                    <span v-if="issueForm.errors.notes" class="error">{{ issueForm.errors.notes }}</span>
                </div>
                <p class="hint">
                    ¿Falta una categoría? Se administran en
                    <a :href="route('backoffice.license-categories.index')">Categorías de licencia</a>.
                </p>

                <h3 class="section-title assign-title">Activación</h3>
                <LicenseAssignmentFields :form="issueForm" id-prefix="issue-assign" allow-none />
            </form>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div class="full">
                    <dt>Código de activación</dt>
                    <dd class="code-reveal" aria-live="polite">
                        <template v-if="revealedCode">
                            <code ref="codeEl" class="full-code">{{ revealedCode }}</code>
                            <span class="code-reveal-actions">
                                <button type="button" class="btn btn-ghost" @click="copyCode"><CheckIcon v-if="copied" /> {{ copied ? 'Copiado' : 'Copiar' }}</button>
                                <button type="button" class="btn btn-ghost" @click="hideCode">Ocultar</button>
                            </span>
                        </template>
                        <template v-else>
                            <span class="masked-code">{{ selected.masked_code }}</span>
                            <button type="button" class="btn btn-ghost" :disabled="revealing" @click="revealCode">
                                {{ revealing ? 'Cargando…' : 'Ver código completo' }}
                            </button>
                        </template>
                        <span v-if="revealError" class="reveal-note error-text">{{ revealError }}</span>
                        <span v-if="copyHint" class="reveal-note muted">{{ copyHint }}</span>
                    </dd>
                </div>
                <div>
                    <dt>Categoría</dt>
                    <dd>{{ selected.category?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Vence</dt>
                    <dd>{{ selected.expires_at }}</dd>
                </div>
                <div class="full">
                    <dt>Superusuario</dt>
                    <dd v-if="selected.superuser">
                        {{ selected.superuser.name }} <span class="muted">· {{ selected.superuser.email }}</span>
                    </dd>
                    <dd v-else-if="selected.invitation && selected.invitation.status !== 'accepted'" class="assignment-box">
                        <span class="assignment-head">
                            <strong>{{ selected.invitation.name ?? selected.invitation.email }}</strong>
                            <span class="muted">· {{ selected.invitation.email }}</span>
                            <span class="badge" :class="INVITATION_STATUS[selected.invitation.status].cls">{{ INVITATION_STATUS[selected.invitation.status].label }}</span>
                        </span>
                        <span class="assignment-meta muted">
                            {{ selected.invitation.account_type === 'new' ? 'Cuenta nueva: se crea al aceptar.' : 'Cuenta existente.' }}
                            <template v-if="selected.invitation.requires_password"> Al aceptar va a elegir su contraseña.</template>
                        </span>
                        <span class="assignment-meta muted">
                            <template v-if="selected.invitation.status === 'pending'">El enlace vence el {{ invitationTime(selected.invitation.expires_at) }}.</template>
                            <template v-else>El enlace venció el {{ invitationTime(selected.invitation.expires_at) }}: la licencia no se activó.</template>
                            Correo enviado {{ selected.invitation.send_count }} {{ selected.invitation.send_count === 1 ? 'vez' : 'veces' }}.
                        </span>
                        <span class="assignment-actions">
                            <button type="button" class="btn btn-ghost" @click="resendInvitation"><MailIcon /> Reenviar correo</button>
                            <button type="button" class="btn btn-ghost" @click="startAssign"><PencilIcon /> Cambiar datos</button>
                            <button type="button" class="btn btn-ghost btn-danger-text" @click="cancelInvitation"><XIcon /> Quitar asignación</button>
                        </span>
                    </dd>
                    <dd v-else class="assignment-box">
                        <span class="muted">Todavía no se activó.</span>
                        <span v-if="canAssign(selected)" class="assignment-actions">
                            <button type="button" class="btn btn-ghost" @click="startAssign"><UserPlusIcon /> Asignar a una persona</button>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Compañías</dt>
                    <dd>{{ selected.companies_count }} / {{ selected.max_companies }}</dd>
                </div>
                <div>
                    <dt>Administradores</dt>
                    <dd>{{ selected.admins_count }} / {{ selected.max_admins }}</dd>
                </div>
                <div>
                    <dt>Usuarios</dt>
                    <dd>{{ selected.users_count }} / {{ selected.max_users }}</dd>
                </div>
                <div>
                    <dt>Emitida</dt>
                    <dd>
                        {{ selected.created_at }}
                        <span v-if="selected.issued_by?.name" class="muted">· {{ selected.issued_by.name }}</span>
                    </dd>
                </div>
                <div class="full">
                    <dt>Próximo seguimiento</dt>
                    <dd v-if="selected.next_pending_follow_up">
                        {{ selected.next_pending_follow_up.next_action_date }} — {{ selected.next_pending_follow_up.action_type }}
                    </dd>
                    <dd v-else class="muted">Sin seguimientos pendientes.</dd>
                </div>
                <div class="full">
                    <dt>Notas</dt>
                    <dd>{{ selected.notes || '—' }}</dd>
                </div>
            </dl>

            <section v-if="selected && mode === 'details'" class="history">
                <button
                    type="button"
                    class="history-toggle"
                    aria-controls="license-history"
                    :aria-expanded="historyOpen"
                    @click="toggleHistory"
                >
                    <ChevronRightIcon class="history-chevron" :class="{ open: historyOpen }" />
                    Historial
                </button>

                <div v-if="historyOpen" id="license-history" aria-live="polite">
                    <p v-if="historyLoading && !history.length" class="history-note muted">Cargando…</p>
                    <p v-else-if="historyError" class="history-note error-text">{{ historyError }}</p>
                    <p v-else-if="!history.length" class="history-note muted">Sin movimientos registrados.</p>
                    <ol v-else class="history-list">
                        <li v-for="entry in history" :key="entry.id">
                            <div class="history-head">
                                <strong>{{ HISTORY_ACTIONS[entry.action] ?? entry.action }}</strong>
                                <span class="muted">
                                    {{ historyWhen(entry.at) }} · {{ entry.actor ?? 'Sistema' }}<template v-if="entry.ip"> · <span class="nowrap">IP {{ entry.ip }}</span></template>
                                </span>
                            </div>
                            <ul v-if="historyChanges(entry).length" class="history-changes">
                                <li v-for="change in historyChanges(entry)" :key="change.field">
                                    {{ HISTORY_FIELDS[change.field] ?? change.field }}:
                                    <template v-if="entry.action === 'license_issued'">{{ historyValue(change.field, change.to) }}</template>
                                    <template v-else>{{ historyValue(change.field, change.from) }} → {{ historyValue(change.field, change.to) }}</template>
                                </li>
                            </ul>
                        </li>
                    </ol>
                    <p v-if="historyHasMore" class="history-note muted">Se muestran los últimos 50 movimientos.</p>
                </div>
            </section>

            <form v-if="selected && mode === 'edit'" id="license-edit-form" @submit.prevent="saveEdit">
                <h3 class="section-title">Editar licencia</h3>
                <div class="field">
                    <label for="edit-license-category">Categoría</label>
                    <select id="edit-license-category" v-model="editForm.category_id" required>
                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                    </select>
                    <span v-if="editForm.errors.category_id" class="error">{{ editForm.errors.category_id }}</span>
                </div>
                <div class="field-row quota-row">
                    <div class="field">
                        <label for="edit-license-companies">Empresas máx.</label>
                        <input id="edit-license-companies" v-model="editForm.max_companies" type="number" min="1" required>
                        <span v-if="editForm.errors.max_companies" class="error">{{ editForm.errors.max_companies }}</span>
                    </div>
                    <div class="field">
                        <label for="edit-license-admins">Admins máx.</label>
                        <input id="edit-license-admins" v-model="editForm.max_admins" type="number" min="0" required>
                        <span v-if="editForm.errors.max_admins" class="error">{{ editForm.errors.max_admins }}</span>
                    </div>
                    <div class="field">
                        <label for="edit-license-users">Usuarios máx.</label>
                        <input id="edit-license-users" v-model="editForm.max_users" type="number" min="0" required>
                        <span v-if="editForm.errors.max_users" class="error">{{ editForm.errors.max_users }}</span>
                    </div>
                </div>
                <div class="field">
                    <label for="edit-license-notes">Notas</label>
                    <input id="edit-license-notes" v-model="editForm.notes" type="text">
                    <span v-if="editForm.errors.notes" class="error">{{ editForm.errors.notes }}</span>
                </div>
                <p v-if="editForm.errors.license" class="flash flash-error">{{ editForm.errors.license }}</p>
            </form>

            <form v-if="selected && mode === 'assign'" id="license-assign-form" @submit.prevent="saveAssign">
                <h3 class="section-title">{{ selected.invitation ? 'Cambiar a quién está asignada' : 'Asignar a una persona' }}</h3>
                <p v-if="selected.invitation" class="hint assign-intro">
                    Al guardar se envía un correo nuevo, con otro enlace de 30 minutos; el que se había enviado deja de servir.
                </p>
                <LicenseAssignmentFields :form="assignForm" id-prefix="detail-assign" />
            </form>

            <form v-if="selected && mode === 'renew'" id="license-renew-form" @submit.prevent="saveRenew">
                <h3 class="section-title">Renovar licencia</h3>
                <p class="hint">Vencimiento actual: {{ selected.expires_at }}.</p>
                <div class="field">
                    <label for="renew-license-date">Nueva fecha de vencimiento</label>
                    <input id="renew-license-date" v-model="renewForm.expires_at" type="date" required>
                    <span v-if="renewForm.errors.expires_at" class="error">{{ renewForm.errors.expires_at }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="mode === 'create'">
                    <button type="button" class="btn btn-ghost" @click="closeLicense">Cancelar</button>
                    <button type="submit" form="license-issue-form" class="btn btn-primary" :disabled="issueForm.processing">
                        <template v-if="issueForm.assign_to === 'none'">Emitir</template>
                        <template v-else><MailIcon /> Emitir y enviar correo</template>
                    </button>
                </template>
                <template v-else-if="selected && mode === 'details'">
                    <a :href="route('backoffice.licenses.commercial-profile.show', selected.id)" class="btn btn-ghost">Perfil comercial</a>
                    <button type="button" class="btn btn-ghost" @click="startRenew">Renovar</button>
                    <button v-if="selected.status === 'active'" type="button" class="btn btn-ghost" @click="askConfirm('suspend')">Suspender</button>
                    <button v-if="selected.status === 'suspended'" type="button" class="btn btn-ghost" @click="reactivate">Reactivar</button>
                    <button v-if="selected.status !== 'revoked'" type="button" class="btn btn-ghost btn-danger-text" @click="askConfirm('revoke')">Revocar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit">Editar</button>
                </template>
                <template v-else-if="selected && mode === 'edit'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="license-edit-form" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                </template>
                <template v-else-if="selected && mode === 'renew'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="license-renew-form" class="btn btn-primary" :disabled="renewForm.processing">Renovar</button>
                </template>
                <template v-else-if="selected && mode === 'assign'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="license-assign-form" class="btn btn-primary" :disabled="assignForm.processing"><MailIcon /> Guardar y enviar correo</button>
                </template>
            </template>
        </DetailModal>

        <ConfirmModal
            :open="!!pendingAction"
            :title="pendingAction ? ACTION_COPY[pendingAction.action].title : ''"
            :message="pendingAction ? ACTION_COPY[pendingAction.action].message(pendingAction.license) : ''"
            :confirm-label="pendingAction ? ACTION_COPY[pendingAction.action].confirmLabel : ''"
            danger
            :processing="confirmProcessing"
            @confirm="confirmPendingAction"
            @cancel="pendingAction = null"
        />
    </BackofficeLayout>
</template>

<style scoped>
.hint { margin: 0.75rem 0 0; font-size: 0.78rem; color: var(--color-text-muted); }
.hint a { color: var(--color-primary); }

table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.55rem 0.65rem; border-top: 1px solid var(--color-border); }
/* Solo en modo tabla: hasta 1024px las filas son tarjetas, con su propio
   relleno. */
@media (min-width: 1025px) {
    th:first-child, td:first-child { padding-left: 1rem; }
    th:last-child, td:last-child { padding-right: 1rem; }
}
/* El código y la fecha no se parten: "CONTAPP-****-" en una línea y el
   resto en otra no se lee como un código. */
.code-cell, .date-cell { white-space: nowrap; }
.code-cell { font-variant-numeric: tabular-nums; font-weight: 600; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }

.section-title { margin-top: 0; }
.assign-title { margin-top: 1.25rem; }
.assign-intro { margin: 0 0 0.75rem; }

/* La asignación pendiente, en la ficha: a quién, en qué quedó el correo, y
   qué se puede hacer. */
.assignment-box { display: flex; flex-direction: column; gap: 0.3rem; }
.assignment-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.5rem; overflow-wrap: anywhere; }
.assignment-meta { font-size: 0.8rem; }
.assignment-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.3rem; }
.assignment-actions .btn { padding: 0.25rem 0.6rem; font-size: 0.78rem; }

.code-reveal { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem 0.6rem; }
.code-reveal .btn { padding: 0.25rem 0.6rem; font-size: 0.78rem; }
.masked-code, .full-code { font-variant-numeric: tabular-nums; font-weight: 600; letter-spacing: 0.02em; }
/* user-select: all — un clic selecciona la clave entera, sin arrastrar. */
.full-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--color-surface-alt);
    padding: 0.2rem 0.45rem;
    border-radius: var(--radius-sm);
    user-select: all;
}
/* Copiar y Ocultar bajan juntos de línea, no uno por uno. */
.code-reveal-actions { display: inline-flex; gap: 0.4rem; }
.reveal-note { flex-basis: 100%; font-size: 0.76rem; }
.error-text { color: var(--color-danger); }

.history { margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border); }
.history-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.2rem 0;
    border: 0;
    background: none;
    color: var(--color-text);
    font: inherit;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
}
.history-chevron { display: inline-block; color: var(--color-text-muted); transition: transform .15s ease; }
.history-chevron.open { transform: rotate(90deg); }
.history-list { list-style: none; margin: 0.6rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.65rem; }
/* La línea a la izquierda agrupa cada movimiento con sus cambios. */
.history-list > li { padding-left: 0.75rem; border-left: 2px solid var(--color-border); }
.history-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.1rem 0.5rem; font-size: 0.84rem; }
.history-head .muted { font-size: 0.76rem; }
.history-changes { margin: 0.2rem 0 0; padding-left: 1rem; font-size: 0.8rem; color: var(--color-text-muted); overflow-wrap: anywhere; }
.history-note { margin: 0.5rem 0 0; font-size: 0.8rem; }
.nowrap { white-space: nowrap; }
/* Los tres cupos son números cortos: caben de a tres por línea incluso en
   un teléfono. */
.quota-row .field { min-width: 5.5rem; }
</style>
