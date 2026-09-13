<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import AuthShell from '../../../Components/AuthShell.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('backoffice.login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Backoffice · Iniciar sesión" />

    <AuthShell dark subtitle="Panel del Propietario">
        <template #panel-extra>
            <p class="internal-note">
                Este acceso es exclusivo del equipo que administra CONTAPP como producto:
                licencias, categorías y el catálogo de indicadores de IVA. No es el acceso
                de ninguna compañía cliente.
            </p>
        </template>

        <h2 class="form-title">Iniciar sesión</h2>
        <p class="internal-badge">Uso interno · Equipo CONTAPP</p>

        <form @submit.prevent="submit">
            <div class="field">
                <label for="email">Correo</label>
                <input id="email" v-model="form.email" type="email" autofocus required>
                <span v-if="form.errors.email" class="error">{{ form.errors.email }}</span>
            </div>

            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" v-model="form.password" type="password" required>
                <span v-if="form.errors.password" class="error">{{ form.errors.password }}</span>
            </div>

            <label class="remember">
                <input v-model="form.remember" type="checkbox">
                Recordarme
            </label>

            <button type="submit" class="btn btn-primary login-submit" :disabled="form.processing">
                Ingresar
            </button>
        </form>
    </AuthShell>
</template>

<style scoped>
.form-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 0.5rem;
}

.internal-badge {
    display: inline-block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
    color: #1a1d22;
    background: #f0b429;
    padding: 0.2rem 0.5rem;
    border-radius: var(--radius-sm);
    margin: 0 0 1.25rem;
}

.internal-note {
    font-size: 0.85rem;
    line-height: 1.55;
    opacity: .85;
    margin: 0;
}

.remember {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.82rem;
    color: var(--color-text-muted);
    margin-bottom: 1.2rem;
}

.login-submit {
    width: 100%;
    justify-content: center;
}
</style>
