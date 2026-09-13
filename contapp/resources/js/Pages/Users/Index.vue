<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ConfirmModal from '../../Components/ConfirmModal.vue';

defineProps({
    users: { type: Array, default: () => [] },
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

const ACCESS_LABELS = { none: null, read: 'Lectura', read_write: 'Lectura/escritura' };

function grantedPermissions(user) {
    return user.permissions.filter((p) => p.access_level !== 'none');
}

// Confirmación real antes de suspender/reactivar/desactivar: son acciones
// que le cierran el acceso a una persona, no algo para disparar con un solo
// clic sin darse cuenta.
const pendingAction = ref(null); // { user, action: 'suspend'|'reactivate'|'deactivate' }
const processing = ref(false);

const ACTION_COPY = {
    suspend: { title: 'Suspender usuario', confirmLabel: 'Suspender', danger: true, message: (n) => `${n} no va a poder ingresar hasta que lo reactivés. Sus permisos quedan guardados tal cual están.` },
    reactivate: { title: 'Reactivar usuario', confirmLabel: 'Reactivar', danger: false, message: (n) => `${n} va a recuperar acceso con los mismos permisos que tenía antes de suspenderlo.` },
    deactivate: { title: 'Desactivar usuario', confirmLabel: 'Desactivar', danger: true, message: (n) => `${n} pierde el acceso de forma permanente. No se borra su historial (asientos, auditoría), pero no hay vuelta atrás desde acá.` },
};

function askConfirm(user, action) {
    pendingAction.value = { user, action };
}

function cancelConfirm() {
    pendingAction.value = null;
}

function confirmAction() {
    if (!pendingAction.value) return;

    const { user, action } = pendingAction.value;
    const routeName = { suspend: 'users.suspend', reactivate: 'users.reactivate', deactivate: 'users.deactivate' }[action];

    processing.value = true;
    router.post(route(routeName, user.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            pendingAction.value = null;
        },
    });
}
</script>

<template>
    <Head title="Usuarios" />

    <AppLayout title="Usuarios">
        <template #actions>
            <Link :href="route('users.create')" class="btn btn-primary">+ Nuevo usuario</Link>
        </template>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Permisos</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td>{{ user.name }}</td>
                        <td>{{ user.email }}</td>
                        <td><span class="badge" :class="user.role_type === 'super_admin' ? 'badge-success' : 'badge-neutral'">{{ roleLabel(user.role_type) }}</span></td>
                        <td><span class="badge" :class="statusInfo(user.status).cls">{{ statusInfo(user.status).label }}</span></td>
                        <td>
                            <span v-if="user.role_type === 'super_admin'" class="muted">Todo (dueño de la licencia)</span>
                            <span v-else-if="!grantedPermissions(user).length" class="muted">Sin permisos otorgados</span>
                            <span v-else class="permission-tags">
                                <span v-for="p in grantedPermissions(user)" :key="p.module.id" class="badge badge-neutral">
                                    {{ p.module.name }}: {{ ACCESS_LABELS[p.access_level] }}
                                </span>
                            </span>
                        </td>
                        <td class="actions-cell">
                            <template v-if="user.role_type !== 'super_admin'">
                                <Link :href="route('users.permissions.edit', user.id)" class="btn btn-ghost">Editar permisos</Link>
                                <template v-if="user.can_manage">
                                    <button v-if="user.status === 'active'" type="button" class="btn btn-ghost" @click="askConfirm(user, 'suspend')">Suspender</button>
                                    <button v-if="user.status === 'suspended'" type="button" class="btn btn-ghost" @click="askConfirm(user, 'reactivate')">Reactivar</button>
                                    <button v-if="user.status !== 'deactivated'" type="button" class="btn btn-ghost btn-danger-text" @click="askConfirm(user, 'deactivate')">Desactivar</button>
                                </template>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!users.length">
                        <td colspan="6" class="muted empty-row">Todavía no hay otros usuarios en esta compañía.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmModal
            :open="!!pendingAction"
            :title="pendingAction ? ACTION_COPY[pendingAction.action].title : ''"
            :message="pendingAction ? ACTION_COPY[pendingAction.action].message(pendingAction.user.name) : ''"
            :confirm-label="pendingAction ? ACTION_COPY[pendingAction.action].confirmLabel : ''"
            :danger="pendingAction ? ACTION_COPY[pendingAction.action].danger : false"
            :processing="processing"
            @confirm="confirmAction"
            @cancel="cancelConfirm"
        />
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.6rem 1rem; border-top: 1px solid var(--color-border); vertical-align: top; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.permission-tags { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.badge-neutral { background: var(--color-surface-alt); color: var(--color-text); }
.actions-cell { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.btn-danger-text { color: var(--color-danger, #c0392b); }
</style>
