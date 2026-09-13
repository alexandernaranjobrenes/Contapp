<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AuthShell from '../../Components/AuthShell.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}

const highlights = [
    'Multiempresa: una sola cuenta, todas tus compañías',
    'Catálogo de cuentas y tipos de documento configurables',
    'Conciliaciones bancarias y diferencial cambiario',
];
</script>

<template>
    <Head title="Iniciar sesión" />

    <AuthShell subtitle="Contabilidad multiempresa para Costa Rica">
        <template #panel-extra>
            <ul class="highlight-list">
                <li v-for="item in highlights" :key="item">{{ item }}</li>
            </ul>
        </template>

        <h2 class="form-title">Iniciar sesión</h2>

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

        <p class="activate-link">
            ¿Tenés un código de licencia? <Link :href="route('license-activation.create')">Activarlo acá</Link>
        </p>
    </AuthShell>
</template>

<style scoped>
.form-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 1.25rem;
}

.highlight-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    font-size: 0.88rem;
    opacity: .9;
}

.highlight-list li {
    padding-left: 1.3em;
    position: relative;
}

.highlight-list li::before {
    content: '✓';
    position: absolute;
    left: 0;
    opacity: .8;
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

.activate-link {
    text-align: center;
    font-size: 0.8rem;
    color: var(--color-text-muted);
    margin-top: 1.25rem;
}

.activate-link a {
    color: var(--color-primary);
    font-weight: 600;
}
</style>
