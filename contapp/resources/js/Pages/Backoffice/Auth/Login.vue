<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthShell from '../../../Components/AuthShell.vue';
import { EyeIcon, EyeOffIcon, KeyRoundIcon, LayersIcon, LockIcon, LogInIcon, MailIcon, PercentIcon } from '@lucide/vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

function submit() {
    form.post(route('backoffice.login'), {
        onFinish: () => form.reset('password'),
    });
}

const scope = [
    { icon: KeyRoundIcon, text: 'Licencias de cada cliente: emisión, renovación y suspensión.' },
    { icon: LayersIcon, text: 'Categorías de licencia y sus cupos.' },
    { icon: PercentIcon, text: 'El catálogo nacional de indicadores de IVA.' },
];
</script>

<template>
    <Head title="Backoffice · Iniciar sesión" />

    <AuthShell
        dark
        subtitle="Panel del Propietario"
        lead="Acceso exclusivo del equipo que administra CONTAPP como producto. No es el acceso de ninguna compañía cliente."
    >
        <template #panel-extra>
            <ul class="scope">
                <li v-for="item in scope" :key="item.text">
                    <span class="scope-icon"><component :is="item.icon" :size="17" /></span>
                    {{ item.text }}
                </li>
            </ul>
        </template>

        <header class="auth-head">
            <span class="internal-badge">Uso interno · Equipo CONTAPP</span>
            <h2 class="auth-title">Iniciar sesión</h2>
        </header>

        <form novalidate @submit.prevent="submit">
            <p v-if="form.errors.email" class="flash flash-error auth-error" role="alert">{{ form.errors.email }}</p>

            <div class="field">
                <label for="email">Correo</label>
                <div class="input-with-icon" :class="{ invalid: form.errors.email }">
                    <MailIcon class="input-icon" :size="17" />
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        autofocus
                        required
                        :aria-invalid="!!form.errors.email"
                    >
                </div>
            </div>

            <div class="field">
                <label for="password">Contraseña</label>
                <div class="input-with-icon" :class="{ invalid: form.errors.password }">
                    <LockIcon class="input-icon" :size="17" />
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        :aria-invalid="!!form.errors.password"
                    >
                    <button
                        type="button"
                        class="reveal"
                        :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                        :title="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                        @click="showPassword = !showPassword"
                    >
                        <EyeOffIcon v-if="showPassword" :size="17" />
                        <EyeIcon v-else :size="17" />
                    </button>
                </div>
                <span v-if="form.errors.password" class="error">{{ form.errors.password }}</span>
            </div>

            <label class="check remember">
                <input v-model="form.remember" type="checkbox">
                Mantener la sesión iniciada
            </label>

            <button type="submit" class="btn btn-primary auth-submit" :disabled="form.processing">
                <LogInIcon /> Ingresar
            </button>
        </form>
    </AuthShell>
</template>

<style scoped>
.scope {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    font-size: 0.9rem;
    line-height: 1.45;
    color: rgba(238, 240, 243, 0.78);
}

.scope li {
    display: flex;
    align-items: center;
    gap: 0.8rem;
}

.scope-icon {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.06);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.10);
    color: #f0b429;
}

.internal-badge + .auth-title {
    margin-top: 0.6rem;
}

/* Ámbar sobre grafito: la marca de esta puerta, distinta a propósito de la
   de los clientes. */
.internal-badge {
    display: inline-block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #1a1d22;
    background: #f0b429;
    padding: 0.2rem 0.55rem;
    border-radius: var(--radius-sm);
}

.field {
    margin-bottom: 1.1rem;
}

.remember {
    margin: 0.25rem 0 1.4rem;
    color: var(--color-text-muted);
}
</style>
