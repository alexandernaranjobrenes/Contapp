<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';
import { useRecordDetail } from '../../Utils/recordDetail';
import { KeyRoundIcon, PlusIcon } from '@lucide/vue';

const props = defineProps({
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

function permissionsSummary(user) {
    if (user.role_type === 'super_admin') return 'Todo';

    const count = grantedPermissions(user).length;
    return count ? `${count} módulo(s)` : 'Ninguno';
}

// Ficha del usuario (CLAUDE.md secc. 20): sus permisos y las acciones.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.users);

// Confirmación real antes de suspender/reactivar/desactivar: son acciones
// que le cierran el acceso a una persona, no algo para disparar con un solo
// clic sin darse cuenta.
const ACTION_COPY = {
    suspend: { route: 'users.suspend', title: 'Suspender usuario', confirmLabel: 'Suspender', danger: true, message: (n) => `${n} no va a poder ingresar hasta que lo reactivés. Sus permisos quedan guardados tal cual están.` },
    reactivate: { route: 'users.reactivate', title: 'Reactivar usuario', confirmLabel: 'Reactivar', danger: false, message: (n) => `${n} va a recuperar acceso con los mismos permisos que tenía antes de suspenderlo.` },
    deactivate: { route: 'users.deactivate', title: 'Desactivar usuario', confirmLabel: 'Desactivar', danger: true, message: (n) => `${n} pierde el acceso de forma permanente. No se borra su historial (asientos, auditoría), pero no hay vuelta atrás desde acá.` },
};

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
                            <td>{{ user.name }}</td>
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
                <p v-else-if="!grantedPermissions(selected).length" class="muted small">Sin permisos otorgados.</p>
                <div v-else class="permission-tags">
                    <span v-for="p in grantedPermissions(selected)" :key="p.module.id" class="badge badge-neutral">
                        {{ p.module.name }}: {{ ACCESS_LABELS[p.access_level] }}
                    </span>
                </div>
            </template>

            <template #actions>
                <template v-if="selected && selected.role_type !== 'super_admin'">
                    <template v-if="selected.can_manage">
                        <button v-if="selected.status !== 'deactivated'" type="button" class="btn btn-ghost btn-danger-text" @click="askConfirm('deactivate')">Desactivar</button>
                        <button v-if="selected.status === 'active'" type="button" class="btn btn-ghost" @click="askConfirm('suspend')">Suspender</button>
                        <button v-if="selected.status === 'suspended'" type="button" class="btn btn-ghost" @click="askConfirm('reactivate')">Reactivar</button>
                    </template>
                    <Link :href="route('users.permissions.edit', selected.id)" class="btn btn-primary"><KeyRoundIcon /> Editar permisos</Link>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.email-cell { overflow-wrap: anywhere; }
.permission-tags { display: flex; flex-wrap: wrap; gap: 0.3rem; }
</style>
