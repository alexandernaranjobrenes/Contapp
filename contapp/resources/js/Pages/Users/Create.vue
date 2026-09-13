<script setup>
import { ref } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    grantableRoleTypes: { type: Array, default: () => [] },
    license: { type: Object, default: null },
    modules: { type: Array, default: () => [] },
});

const ROLE_LABELS = { admin: 'Administrador', user: 'Usuario' };
const LEVELS = ['none', 'read', 'read_write'];
const LEVEL_LABELS = { none: 'Sin acceso', read: 'Lectura', read_write: 'Lectura/escritura' };

function levelRank(level) {
    return LEVELS.indexOf(level);
}

function selectableLevels(module) {
    return LEVELS.filter((level) => levelRank(level) <= levelRank(module.max_access_level));
}

// "Crear usuario nuevo" da de alta una cuenta desde cero; "Invitar cuenta
// existente" vincula a esta compañía a alguien que ya tiene credenciales
// propias en CONTAPP (de otra licencia u otra compañía) sin tocar su
// contraseña — es la identidad "no amarrada a una sola cuenta" (ver
// PermissionGrantService::inviteUser()).
const mode = ref('create'); // 'create' | 'invite'

const form = useForm({
    name: '',
    email: '',
    password: '',
    role_type: props.grantableRoleTypes[0] ?? '',
    permissions: Object.fromEntries(props.modules.map((m) => [m.id, 'none'])),
});

const inviteForm = useForm({
    email: '',
    role_type: props.grantableRoleTypes[0] ?? '',
    permissions: Object.fromEntries(props.modules.map((m) => [m.id, 'none'])),
});

const lookup = ref(null); // { exists, name } | null
const lookupLoading = ref(false);
let lookupToken = 0;

async function checkEmail() {
    const email = inviteForm.email.trim();
    lookup.value = null;
    if (!email) return;

    const token = ++lookupToken;
    lookupLoading.value = true;
    try {
        const res = await fetch(`${route('users.lookup')}?email=${encodeURIComponent(email)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (token === lookupToken) lookup.value = data;
    } finally {
        if (token === lookupToken) lookupLoading.value = false;
    }
}

function submit() {
    form.post(route('users.store'));
}

function submitInvite() {
    inviteForm.post(route('users.invite'));
}
</script>

<template>
    <Head title="Nuevo usuario" />

    <AppLayout title="Nuevo usuario">
        <template #actions>
            <Link :href="route('users.index')" class="btn btn-ghost">← Volver</Link>
        </template>

        <div class="card form-card">
            <p v-if="license" class="quota">
                Administradores: {{ license.admins_count }} de {{ license.max_admins }} · Usuarios: {{ license.users_count }} de {{ license.max_users }}
            </p>

            <div class="mode-tabs">
                <button type="button" class="btn" :class="mode === 'create' ? 'btn-primary' : 'btn-ghost'" @click="mode = 'create'">Crear usuario nuevo</button>
                <button type="button" class="btn" :class="mode === 'invite' ? 'btn-primary' : 'btn-ghost'" @click="mode = 'invite'">Invitar cuenta existente</button>
            </div>

            <p v-if="!grantableRoleTypes.length" class="error">
                Tu licencia ya alcanzó el cupo de administradores y usuarios. Contactá al soporte de CONTAPP si necesitás más cupo.
            </p>

            <form v-if="mode === 'create'" @submit.prevent="submit">
                <div class="field">
                    <label>Nombre</label>
                    <input v-model="form.name" type="text" required>
                    <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
                </div>
                <div class="field">
                    <label>Correo</label>
                    <input v-model="form.email" type="email" required>
                    <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>
                </div>
                <div class="field">
                    <label>Contraseña</label>
                    <input v-model="form.password" type="password" required minlength="8">
                    <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
                </div>
                <div class="field">
                    <label>Tipo de rol</label>
                    <select v-model="form.role_type" required>
                        <option v-for="type in grantableRoleTypes" :key="type" :value="type">{{ ROLE_LABELS[type] }}</option>
                    </select>
                </div>

                <h3>Permisos por módulo</h3>
                <p class="hint">Solo podés otorgar hasta el nivel de acceso que vos mismo tenés en cada módulo.</p>
                <table class="permissions-table">
                    <thead>
                        <tr><th>Módulo</th><th>Nivel de acceso</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="module in modules" :key="module.id">
                            <td>{{ module.name }}</td>
                            <td>
                                <select v-model="form.permissions[module.id]">
                                    <option v-for="level in selectableLevels(module)" :key="level" :value="level">{{ LEVEL_LABELS[level] }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.errors.permissions" class="error">{{ form.errors.permissions }}</p>

                <button type="submit" class="btn btn-primary" :disabled="form.processing || !grantableRoleTypes.length">Crear usuario</button>
            </form>

            <form v-else @submit.prevent="submitInvite">
                <div class="field">
                    <label>Correo de la cuenta a invitar</label>
                    <input v-model="inviteForm.email" type="email" required @blur="checkEmail">
                    <p v-if="inviteForm.errors.email" class="error">{{ inviteForm.errors.email }}</p>
                    <p v-if="lookupLoading" class="hint">Buscando...</p>
                    <p v-else-if="lookup && lookup.exists" class="notice">
                        Ya existe una cuenta con este correo (nombre: <strong>{{ lookup.name }}</strong>). Al confirmar, esa cuenta va a poder acceder también a esta compañía con los permisos que le asignes abajo. Si no reconocés a esta persona, cancelá.
                    </p>
                    <p v-else-if="lookup && !lookup.exists" class="error">
                        No existe ninguna cuenta activa con ese correo. Si es una persona nueva, usá "Crear usuario nuevo" en la otra pestaña.
                    </p>
                </div>
                <div class="field">
                    <label>Tipo de rol</label>
                    <select v-model="inviteForm.role_type" required>
                        <option v-for="type in grantableRoleTypes" :key="type" :value="type">{{ ROLE_LABELS[type] }}</option>
                    </select>
                </div>

                <h3>Permisos por módulo</h3>
                <p class="hint">Solo podés otorgar hasta el nivel de acceso que vos mismo tenés en cada módulo.</p>
                <table class="permissions-table">
                    <thead>
                        <tr><th>Módulo</th><th>Nivel de acceso</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="module in modules" :key="module.id">
                            <td>{{ module.name }}</td>
                            <td>
                                <select v-model="inviteForm.permissions[module.id]">
                                    <option v-for="level in selectableLevels(module)" :key="level" :value="level">{{ LEVEL_LABELS[level] }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="inviteForm.errors.permissions" class="error">{{ inviteForm.errors.permissions }}</p>

                <button type="submit" class="btn btn-primary" :disabled="inviteForm.processing || !grantableRoleTypes.length || !(lookup && lookup.exists)">
                    Vincular cuenta
                </button>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem 1.5rem; max-width: 640px; }
.quota { font-size: 0.85rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.mode-tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
.field { margin-bottom: 0.9rem; display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; color: var(--color-text-muted); }
input, select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.88rem;
}
h3 { font-size: 0.88rem; margin: 1.25rem 0 0.25rem; }
.hint { font-size: 0.78rem; color: var(--color-text-muted); margin: 0 0 0.6rem; }
.notice { font-size: 0.78rem; color: var(--color-text); background: var(--color-warning-soft); padding: 0.5rem 0.6rem; border-radius: var(--radius-sm); margin: 0; }
.permissions-table { width: 100%; font-size: 0.85rem; margin-bottom: 0.75rem; }
.permissions-table th, .permissions-table td { text-align: left; padding: 0.4rem 0.2rem; border-top: 1px solid var(--color-border); }
.error { color: var(--color-danger); font-size: 0.78rem; margin: 0.2rem 0 0; }
</style>
