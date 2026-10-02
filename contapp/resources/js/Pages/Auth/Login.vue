<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';
import {
    ArrowRightIcon, Building2Icon, EyeIcon, EyeOffIcon, LandmarkIcon, LockIcon, LogInIcon, MailIcon, ScaleIcon,
} from '@lucide/vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}

const highlights = [
    { icon: Building2Icon, title: 'Multiempresa', text: 'Una sola cuenta para todas tus compañías, cada una con su tema.' },
    { icon: ScaleIcon, title: 'Contabilidad al día', text: 'Catálogo de cuentas, tipos de documento y cierres de período.' },
    { icon: LandmarkIcon, title: 'Bancos y cambiario', text: 'Conciliaciones bancarias y diferencial cambiario en colones y dólares.' },
];
</script>

<template>
    <Head title="Iniciar sesión" />

    <AuthShell
        subtitle="Contabilidad multiempresa para Costa Rica"
        lead="Registrá, conciliá y reportá la contabilidad de todas tus compañías desde un solo lugar."
    >
        <template #panel-extra>
            <ul class="highlights">
                <li v-for="item in highlights" :key="item.title">
                    <span class="highlight-icon"><component :is="item.icon" :size="18" /></span>
                    <span>
                        <strong>{{ item.title }}</strong>
                        <span class="highlight-text">{{ item.text }}</span>
                    </span>
                </li>
            </ul>

            <!-- Adorno: una vista de la aplicación, en vidrio esmerilado. -->
            <div class="glass-card" aria-hidden="true">
                <div class="glass-head">
                    <span>Balance de comprobación</span>
                    <span class="glass-badge">Cuadra</span>
                </div>
                <div class="glass-row"><span class="glass-bar" style="width: 62%"></span><span class="glass-amount">₡ 70 411 960</span></div>
                <div class="glass-row"><span class="glass-bar" style="width: 48%"></span><span class="glass-amount">₡ 70 411 960</span></div>
                <div class="glass-row muted"><span class="glass-bar" style="width: 34%"></span><span class="glass-amount">₡ 0,00</span></div>
            </div>
        </template>

        <header class="auth-head">
            <h2 class="auth-title">Iniciar sesión</h2>
            <p class="auth-subtitle">Ingresá con tu correo y tu contraseña.</p>
        </header>

        <form novalidate @submit.prevent="submit">
            <!-- Las credenciales incorrectas llegan como error del correo: se
                 dicen arriba, en una línea, sin señalar cuál de los dos falló. -->
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
                        placeholder="nombre@empresa.com"
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

            <!-- El enlace va después de la contraseña, no al lado de su
                 etiqueta: así el tabulador pasa del correo a la contraseña
                 sin detenerse en él. -->
            <div class="login-options">
                <label class="check remember">
                    <input v-model="form.remember" type="checkbox">
                    Mantener la sesión iniciada
                </label>
                <Link :href="route('password.request')" class="forgot-link">¿Olvidaste tu contraseña?</Link>
            </div>

            <button type="submit" class="btn btn-primary auth-submit" :disabled="form.processing">
                <LogInIcon /> Ingresar
            </button>
        </form>

        <div class="auth-alt">
            <span>¿Tenés un código de licencia?</span>
            <Link :href="route('license-activation.create')" class="auth-alt-link">Activarlo acá <ArrowRightIcon :size="15" /></Link>
        </div>

        <template #below>
            ¿Problemas para ingresar? Pedile al administrador de tu compañía que revise tu usuario.
        </template>
    </AuthShell>
</template>

<style scoped>
/* ── Panel de marca ───────────────────────────────────────────────────── */

.highlights {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.highlights li {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
    font-size: 0.9rem;
    line-height: 1.45;
}

.highlight-icon {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.08);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.10);
    color: var(--color-accent);
}

.highlights strong {
    display: block;
    font-weight: 700;
}

.highlight-text {
    color: rgba(244, 246, 250, 0.68);
}

.glass-card {
    margin-top: 2.25rem;
    max-width: 22rem;
    padding: 1rem 1.1rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.07);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12), 0 20px 40px -18px rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.glass-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    font-size: 0.78rem;
    font-weight: 700;
}

.glass-badge {
    padding: 0.12rem 0.5rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-accent) 22%, transparent);
    color: var(--color-accent);
    font-size: 0.64rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.glass-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.4rem 0;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.glass-bar {
    height: 0.4rem;
    border-radius: 4px;
    background: rgba(255, 255, 255, 0.18);
}

.glass-amount {
    font-size: 0.76rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.glass-row.muted .glass-amount {
    color: rgba(244, 246, 250, 0.55);
}

/* La tarjeta de vidrio solo donde hay aire: en pantallas bajas o angostas
   empujaría el contenido. */
@media (max-width: 1100px), (max-height: 760px) {
    .glass-card { display: none; }
}

/* ── Formulario ───────────────────────────────────────────────────────── */

.field {
    margin-bottom: 1.1rem;
}

/* La casilla a la izquierda y el enlace a la derecha; en un teléfono angosto
   el enlace baja a su propia línea. */
.login-options {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem 0.75rem;
    margin: 0.25rem 0 1.4rem;
    font-size: 0.82rem;
}

.remember {
    margin: 0;
    color: var(--color-text-muted);
}

.forgot-link {
    color: var(--auth-link);
    font-weight: 600;
    text-decoration: none;
}

.forgot-link:hover {
    text-decoration: underline;
}
</style>
