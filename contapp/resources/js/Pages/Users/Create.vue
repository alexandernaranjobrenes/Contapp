<script setup>
import { ref } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ScreenPermissionsEditor from '../../Components/ScreenPermissionsEditor.vue';
import PermissionProfilePicker from '../../Components/PermissionProfilePicker.vue';
import ContiAccessFields from '../../Components/Conti/ContiAccessFields.vue';
import { ArrowLeftIcon, MailIcon } from '@lucide/vue';

/**
 * Dar de alta a una persona es invitarla (CompanyInvitationService): acá se
 * pone su correo, su rol y sus permisos por pantalla del menú. Le llega un
 * correo para aceptar; si no tiene cuenta en CONTAPP, ahí elige su nombre y
 * su contraseña. Quien invita nunca pone la contraseña de nadie.
 *
 * Un perfil del rol (Contador, Vendedor…) precarga los permisos; no se envía.
 *
 * El Superusuario además decide lo de Conti (ContiAccessFields.vue): si va a
 * poder usarlo, sus límites y sus modelos. A nadie más le llega «conti».
 */
const props = defineProps({
    grantableRoleTypes: { type: Array, default: () => [] },
    // «quota» y no «license»: con ese nombre pisaría la licencia que comparte el
    // servidor para el pie de página (HandleInertiaRequests).
    quota: { type: Object, default: null },
    sections: { type: Array, required: true },
    // Por rol: para precargar los permisos (PermissionProfilePicker.vue).
    profiles: { type: Object, default: () => ({}) },
    expiresInDays: { type: Number, required: true },
    conti: { type: Object, default: null },
});

const ROLE_LABELS = { admin: 'Administrador', user: 'Usuario' };

const form = useForm({
    email: '',
    role_type: props.grantableRoleTypes[0] ?? '',
    permissions: Object.fromEntries(props.sections.flatMap((s) => s.screens.map((sc) => [sc.key, 'none']))),
    ...(props.conti ? { conti: { enabled: true, daily_credits: '', weekly_credits: '', models: [...props.conti.current.models] } } : {}),
});

// ¿El correo ya tiene cuenta en CONTAPP? Solo para avisar qué va a pasar.
const lookup = ref(null); // { exists, name, member } | null
const lookupLoading = ref(false);
let lookupToken = 0;

async function checkEmail() {
    const email = form.email.trim();
    lookup.value = null;
    if (!email || !email.includes('@')) return;

    const token = ++lookupToken;
    lookupLoading.value = true;
    try {
        const res = await fetch(`${route('users.lookup')}?email=${encodeURIComponent(email)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.ok && token === lookupToken) lookup.value = await res.json();
    } catch {
        // Sin el aviso se puede invitar igual: el servidor revisa todo.
    } finally {
        if (token === lookupToken) lookupLoading.value = false;
    }
}

function submit() {
    form.post(route('users.store'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Invitar persona" />

    <AppLayout title="Invitar persona">
        <div class="view-toolbar">
            <Link :href="route('users.index')" class="btn btn-ghost"><ArrowLeftIcon /> Usuarios</Link>
        </div>

        <div class="card form-card">
            <p class="intro">
                Le enviamos un correo con un enlace para aceptar. Si todavía no tiene cuenta en CONTAPP, ahí elige su
                nombre y su contraseña. Hasta que acepte no entra a la compañía. El enlace vence en {{ expiresInDays }} días.
            </p>

            <p v-if="quota" class="quota">
                Administradores: {{ quota.admins_count }} de {{ quota.max_admins }}<template v-if="quota.pending_admins"> (+{{ quota.pending_admins }} invitado{{ quota.pending_admins === 1 ? '' : 's' }})</template>
                · Usuarios: {{ quota.users_count }} de {{ quota.max_users }}<template v-if="quota.pending_users"> (+{{ quota.pending_users }} invitado{{ quota.pending_users === 1 ? '' : 's' }})</template>
            </p>

            <p v-if="!grantableRoleTypes.length" class="error">
                Tu licencia ya alcanzó el cupo de administradores y usuarios. Contactá al soporte de CONTAPP si necesitás más cupo.
            </p>

            <form id="invite-form" @submit.prevent="submit">
                <div class="form-grid">
                    <div class="field">
                        <label for="invite-email">Correo</label>
                        <input
                            id="invite-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            required
                            @blur="checkEmail"
                            @input="form.clearErrors('email'); lookup = null"
                        >
                        <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>
                        <p v-else-if="lookupLoading" class="hint">Buscando…</p>
                        <p v-else-if="lookup?.member" class="notice">Esa persona ya tiene acceso a esta compañía.</p>
                        <p v-else-if="lookup?.exists" class="notice">
                            Ya tiene cuenta en CONTAPP: <strong>{{ lookup.name }}</strong>. Al aceptar, esta compañía se suma
                            a las que ya ve, con su misma contraseña. Si no reconocés a esta persona, revisá el correo.
                        </p>
                        <p v-else-if="lookup && !lookup.exists" class="hint">
                            No tiene cuenta en CONTAPP: la crea al aceptar, con su nombre y su contraseña.
                        </p>
                    </div>
                    <div class="field">
                        <label for="invite-role">Rol</label>
                        <select id="invite-role" v-model="form.role_type" required>
                            <option v-for="type in grantableRoleTypes" :key="type" :value="type">{{ ROLE_LABELS[type] }}</option>
                        </select>
                        <p v-if="form.errors.role_type" class="error">{{ form.errors.role_type }}</p>
                    </div>
                </div>

                <PermissionProfilePicker
                    v-model="form.permissions"
                    :profiles="profiles[form.role_type] ?? []"
                    :sections="sections"
                    id="invite-profile"
                />

                <h3>Permisos</h3>
                <p class="hint">
                    Por cada opción del menú. «Lectura» deja consultar y exportar; «Lectura y escritura», además crear,
                    modificar y eliminar. Solo podés dar hasta el acceso que tenés vos.
                </p>
                <p v-if="form.errors.permissions" class="error permissions-error">{{ form.errors.permissions }}</p>

                <ScreenPermissionsEditor v-model="form.permissions" :sections="sections" />

                <ContiAccessFields v-if="conti" v-model="form.conti" :options="conti" :errors="form.errors" />

                <div class="form-actions">
                    <Link :href="route('users.index')" class="btn btn-ghost">Cancelar</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing || !grantableRoleTypes.length">
                        <MailIcon /> Enviar invitación
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem 1.5rem; }
.intro { font-size: 0.86rem; line-height: 1.5; margin: 0 0 0.6rem; }
.quota { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 1rem; }
h3 { font-size: 0.88rem; margin: 1.25rem 0 0.25rem; }
.hint { font-size: 0.78rem; margin: 0.2rem 0 0.6rem; }
.notice { font-size: 0.78rem; color: var(--color-text); background: var(--color-warning-soft); padding: 0.5rem 0.6rem; border-radius: var(--radius-sm); margin: 0.3rem 0 0; }
.error { color: var(--color-danger); font-size: 0.78rem; margin: 0.2rem 0 0; }
.permissions-error { margin-bottom: 0.6rem; }
.form-actions { margin-top: 1rem; }

@media (max-width: 640px) {
    .form-card { padding: 1rem; }
}
</style>
