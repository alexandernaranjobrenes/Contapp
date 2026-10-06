<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import UserAvatar from '../../Components/UserAvatar.vue';
import { confirmAction } from '../../Utils/confirm';
import { useRecordDetail } from '../../Utils/recordDetail';
import { fullDate, timeAgo } from '../../Utils/relativeTime';
import { ArrowUpDownIcon, KeyRoundIcon, MailIcon, PlusIcon, XIcon } from '@lucide/vue';

/**
 * Las personas de la compañía y las invitaciones que todavía no se
 * aceptaron. «Crear nuevo» invita (CompanyInvitationService): la persona
 * entra recién cuando acepta desde el correo.
 */
const props = defineProps({
    users: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
});

const ROLE_LABELS = {
    super_admin: 'Superusuario',
    admin: 'Administrador',
    user: 'Usuario',
};

function roleLabel(type) {
    return ROLE_LABELS[type] ?? type ?? '—';
}

const STATUS_LABELS = {
    active: { label: 'Activo', cls: 'badge-success' },
    suspended: { label: 'Suspendido', cls: 'badge-warning' },
    deactivated: { label: 'Desactivado', cls: 'badge-danger' },
};

function statusInfo(status) {
    return STATUS_LABELS[status] ?? { label: status, cls: 'badge-neutral' };
}

const INVITATION_STATUS = {
    pending: { label: 'Pendiente', cls: 'badge-warning' },
    expired: { label: 'Vencida', cls: 'badge-danger' },
};

const ACCESS_LABELS = { read: 'Lectura', read_write: 'Lectura y escritura' };

// Conti (solo lo manda el servidor al Superusuario): límites en créditos.
const creditFormat = new Intl.NumberFormat('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const credits = (value) => (value === null || value === undefined ? 'Sin límite propio' : `${creditFormat.format(Number(value))} créditos`);
const contiModels = (conti) => (conti.models ? conti.models.join(', ') : 'Todos');

function screensCount(access) {
    return (access ?? []).reduce((sum, section) => sum + section.screens.length, 0);
}

function permissionsSummary(user) {
    if (user.role_type === 'super_admin') return 'Todo';

    const count = screensCount(user.access);
    return count ? `${count} pantalla${count === 1 ? '' : 's'}` : 'Ninguno';
}

// Ficha del usuario (CLAUDE.md secc. 20): sus permisos y las acciones.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.users);

// Confirmación real antes de suspender/reactivar/desactivar: son acciones
// que le cierran el acceso a una persona, no algo para disparar con un solo
// clic sin darse cuenta.
// En los tres casos le llega un correo a la persona (UserLifecycleService).
const ACTION_COPY = {
    suspend: { route: 'users.suspend', title: 'Suspender usuario', confirmLabel: 'Suspender', danger: true, message: (n) => `${n} no va a poder ingresar hasta que lo reactivés. Sus permisos quedan guardados tal cual están. Le avisamos por correo.` },
    reactivate: { route: 'users.reactivate', title: 'Reactivar usuario', confirmLabel: 'Reactivar', danger: false, message: (n) => `${n} va a recuperar acceso con los mismos permisos que tenía antes de suspenderlo. Le avisamos por correo.` },
    deactivate: { route: 'users.deactivate', title: 'Desactivar usuario', confirmLabel: 'Desactivar', danger: true, message: (n) => `${n} pierde el acceso de forma permanente. No se borra su historial (asientos, auditoría), pero no hay vuelta atrás desde acá. Le avisamos por correo.` },
};

// Usuario ↔ Administrador (solo el Superusuario). Los permisos por pantalla
// no cambian; cambia si puede invitar y gestionar Usuarios.
function askRoleChange() {
    const user = selected.value;
    const toAdmin = user.role_type === 'user';

    confirmAction({
        title: toAdmin ? 'Hacer Administrador' : 'Hacer Usuario',
        message: toAdmin
            ? `${user.name} va a poder invitar Usuarios y gestionar sus permisos, hasta el acceso que tenga. Sus permisos por pantalla no cambian.`
            : `${user.name} deja de poder invitar y gestionar Usuarios. Sus permisos por pantalla no cambian.`,
        confirmLabel: toAdmin ? 'Hacer Administrador' : 'Hacer Usuario',
        onConfirm: () => router.put(route('users.role', user.id), { role_type: toAdmin ? 'admin' : 'user' }, { preserveScroll: true }),
    });
}

function askConfirm(action) {
    const user = selected.value;
    const copy = ACTION_COPY[action];

    confirmAction({
        title: copy.title,
        message: copy.message(user.name),
        confirmLabel: copy.confirmLabel,
        danger: copy.danger,
        onConfirm: () => router.post(route(copy.route, user.id), {}, { preserveScroll: true }),
    });
}

// ── Invitaciones sin aceptar ──────────────────────────────────────────────
const selectedInvitationId = ref(null);
const selectedInvitation = computed(() => props.invitations.find((i) => i.id === selectedInvitationId.value) ?? null);

function openInvitation(invitation) {
    selectedInvitationId.value = invitation.id;
}

function closeInvitation() {
    selectedInvitationId.value = null;
}

function resendInvitation() {
    router.post(route('users.invitations.resend', selectedInvitation.value.id), {}, { preserveScroll: true });
}

function cancelInvitation() {
    const invitation = selectedInvitation.value;

    confirmAction({
        title: 'Cancelar invitación',
        message: `El enlace que le llegó a ${invitation.email} deja de servir. Si después querés que entre, la invitás de nuevo.`,
        confirmLabel: 'Cancelar invitación',
        danger: true,
        onConfirm: () => router.delete(route('users.invitations.destroy', invitation.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Usuarios" />

    <AppLayout title="Usuarios">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('users.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Correo</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Permisos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(user)"
                            @keydown.enter="openDetail(user)"
                            @keydown.space.prevent="openDetail(user)"
                        >
                            <td><span class="user-cell"><UserAvatar :url="user.photo_url" :name="user.name" /> {{ user.name }}</span></td>
                            <td data-label="Correo" class="email-cell">{{ user.email }}</td>
                            <td data-label="Rol"><span class="badge" :class="user.role_type === 'super_admin' ? 'badge-success' : 'badge-neutral'">{{ roleLabel(user.role_type) }}</span></td>
                            <td data-label="Estado"><span class="badge" :class="statusInfo(user.status).cls">{{ statusInfo(user.status).label }}</span></td>
                            <td data-label="Permisos" class="muted small">{{ permissionsSummary(user) }}</td>
                        </tr>
                        <tr v-if="!users.length">
                            <td colspan="5" class="muted empty-row">Todavía no hay otros usuarios en esta compañía.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <template v-if="invitations.length">
            <h3 class="block-title">Invitaciones sin aceptar</h3>
            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Enviada</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="invitation in invitations"
                                :key="invitation.id"
                                class="clickable-row"
                                tabindex="0"
                                @click="openInvitation(invitation)"
                                @keydown.enter="openInvitation(invitation)"
                                @keydown.space.prevent="openInvitation(invitation)"
                            >
                                <td class="email-cell">{{ invitation.email }}</td>
                                <td data-label="Rol"><span class="badge badge-neutral">{{ roleLabel(invitation.role_type) }}</span></td>
                                <td data-label="Estado"><span class="badge" :class="INVITATION_STATUS[invitation.status]?.cls">{{ INVITATION_STATUS[invitation.status]?.label }}</span></td>
                                <td data-label="Enviada">
                                    <time v-if="invitation.sent_at" :datetime="invitation.sent_at" :title="fullDate(invitation.sent_at)">{{ timeAgo(invitation.sent_at) }}</time>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <DetailModal :open="!!selected" :title="selected?.name ?? ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="statusInfo(selected.status).cls">{{ statusInfo(selected.status).label }}</span>
            </template>

            <template v-if="selected">
                <dl class="detail-list">
                    <div>
                        <dt>Correo</dt>
                        <dd>{{ selected.email }}</dd>
                    </div>
                    <div>
                        <dt>Rol</dt>
                        <dd>{{ roleLabel(selected.role_type) }}</dd>
                    </div>
                </dl>

                <h4 class="block-title">Permisos</h4>
                <p v-if="selected.role_type === 'super_admin'" class="muted small">Todo (dueño de la licencia).</p>
                <p v-else-if="!screensCount(selected.access)" class="muted small">Sin permisos otorgados.</p>
                <div v-else class="access-summary">
                    <div v-for="section in selected.access" :key="section.label" class="access-section">
                        <strong>{{ section.label }}</strong>
                        <ul>
                            <li v-for="screen in section.screens" :key="screen.label">
                                {{ screen.label }} <span class="muted">· {{ ACCESS_LABELS[screen.level] }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <template v-if="selected.conti">
                    <h4 class="block-title">Conti</h4>
                    <p v-if="selected.conti.superuser" class="muted small">
                        Sin límite propio: usa el cupo de la licencia, que comparte con todas las personas.
                        Gastó {{ creditFormat.format(Number(selected.conti.used_today)) }} créditos hoy y {{ creditFormat.format(Number(selected.conti.used_week)) }} esta semana.
                    </p>
                    <dl v-else class="detail-list">
                        <div>
                            <dt>Puede usarlo</dt>
                            <dd>{{ selected.conti.enabled ? 'Sí' : 'No' }}</dd>
                        </div>
                        <template v-if="selected.conti.enabled">
                            <div>
                                <dt>Límite por día</dt>
                                <dd>{{ credits(selected.conti.daily_credits) }}</dd>
                            </div>
                            <div>
                                <dt>Límite por semana</dt>
                                <dd>{{ credits(selected.conti.weekly_credits) }}</dd>
                            </div>
                            <div>
                                <dt>Modelos</dt>
                                <dd>{{ contiModels(selected.conti) }}</dd>
                            </div>
                        </template>
                        <div>
                            <dt>Gastó</dt>
                            <dd>{{ creditFormat.format(Number(selected.conti.used_today)) }} hoy · {{ creditFormat.format(Number(selected.conti.used_week)) }} esta semana</dd>
                        </div>
                    </dl>
                </template>
            </template>

            <template #actions>
                <template v-if="selected && selected.can_manage">
                    <button v-if="selected.status !== 'deactivated'" type="button" class="btn btn-ghost btn-danger-text" @click="askConfirm('deactivate')">Desactivar</button>
                    <button v-if="selected.status === 'active'" type="button" class="btn btn-ghost" @click="askConfirm('suspend')">Suspender</button>
                    <button v-if="selected.status === 'suspended'" type="button" class="btn btn-ghost" @click="askConfirm('reactivate')">Reactivar</button>
                    <button v-if="selected.can_change_role && selected.status !== 'deactivated'" type="button" class="btn btn-ghost" @click="askRoleChange">
                        <ArrowUpDownIcon /> {{ selected.role_type === 'user' ? 'Hacer Administrador' : 'Hacer Usuario' }}
                    </button>
                    <Link :href="route('users.permissions.edit', selected.id)" class="btn btn-primary"><KeyRoundIcon /> Editar permisos</Link>
                </template>
            </template>
        </DetailModal>

        <DetailModal :open="!!selectedInvitation" :title="selectedInvitation?.email ?? ''" @close="closeInvitation">
            <template #badge>
                <span v-if="selectedInvitation" class="badge" :class="INVITATION_STATUS[selectedInvitation.status]?.cls">{{ INVITATION_STATUS[selectedInvitation.status]?.label }}</span>
            </template>

            <template v-if="selectedInvitation">
                <p class="muted small invitation-note">
                    <template v-if="selectedInvitation.status === 'expired'">
                        El enlace venció sin que la persona lo aceptara. Reenviala para mandarle uno nuevo.
                    </template>
                    <template v-else>
                        Todavía no la aceptó: hasta entonces no entra a la compañía. Reenviarla le manda un enlace
                        nuevo y el anterior deja de servir.
                    </template>
                </p>
                <dl class="detail-list">
                    <div>
                        <dt>Rol</dt>
                        <dd>{{ roleLabel(selectedInvitation.role_type) }}</dd>
                    </div>
                    <div>
                        <dt>La invitó</dt>
                        <dd>{{ selectedInvitation.inviter ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Enviada</dt>
                        <dd>
                            {{ selectedInvitation.sent_at ? fullDate(selectedInvitation.sent_at) : '—' }}
                            <template v-if="selectedInvitation.send_count > 1"> ({{ selectedInvitation.send_count }} envíos)</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Vence</dt>
                        <dd>{{ selectedInvitation.expires_at ? fullDate(selectedInvitation.expires_at) : '—' }}</dd>
                    </div>
                </dl>

                <template v-if="selectedInvitation.conti">
                    <h4 class="block-title">Conti al aceptar</h4>
                    <p v-if="!selectedInvitation.conti.enabled" class="muted small">No va a poder usarlo.</p>
                    <p v-else class="muted small">
                        Por día: {{ credits(selectedInvitation.conti.daily_credits) }} · Por semana: {{ credits(selectedInvitation.conti.weekly_credits) }} · Modelos: {{ contiModels(selectedInvitation.conti) }}
                    </p>
                </template>

                <h4 class="block-title">Permisos al aceptar</h4>
                <p v-if="!screensCount(selectedInvitation.access)" class="muted small">Sin permisos: solo va a ver el Panel.</p>
                <div v-else class="access-summary">
                    <div v-for="section in selectedInvitation.access" :key="section.label" class="access-section">
                        <strong>{{ section.label }}</strong>
                        <ul>
                            <li v-for="screen in section.screens" :key="screen.label">
                                {{ screen.label }} <span class="muted">· {{ ACCESS_LABELS[screen.level] }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>

            <template #actions>
                <template v-if="selectedInvitation?.can_manage">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="cancelInvitation"><XIcon /> Cancelar invitación</button>
                    <button type="button" class="btn btn-primary" @click="resendInvitation"><MailIcon /> Reenviar</button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.email-cell { overflow-wrap: anywhere; }
/* flex y no inline-flex: en línea, una foto (que no tiene línea de base de
   texto) se apoya en la del renglón y la fila queda más alta que las demás. */
.user-cell { display: flex; align-items: center; gap: 0.6rem; }
.access-summary {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
    gap: 0.75rem 1rem;
    font-size: 0.84rem;
}
.access-section ul { margin: 0.2rem 0 0; padding-left: 1.1rem; }
.access-section li { margin: 0.1rem 0; }
.invitation-note { margin: 0 0 0.75rem; line-height: 1.5; }
</style>
