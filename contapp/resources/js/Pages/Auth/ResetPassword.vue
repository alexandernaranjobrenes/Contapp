<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';
import PasswordRequirements from '../../Components/PasswordRequirements.vue';
import {
    ArrowLeftIcon, CheckIcon, EyeIcon, EyeOffIcon, KeyRoundIcon, LockIcon, LogOutIcon,
    MailIcon, ShieldCheckIcon, TriangleAlertIcon,
} from '@lucide/vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
    // El servidor ya comprobó el enlace al abrirlo: false si venció, si ya se
    // usó, si se pidió otro después o si no corresponde a ese correo.
    valid: { type: Boolean, required: true },
    expiresInMinutes: { type: Number, required: true },
    passwordRequirements: { type: Array, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const showPassword = ref(false);

// El enlace también puede dejar de servir entre abrirlo y enviar el
// formulario (venció mientras tanto): ahí el servidor contesta con un error
// de token, y se muestra el mismo aviso.
const linkIsDead = computed(() => !props.valid || !!form.errors.token);

// Si el servidor rechaza la contraseña, lo escrito se queda para corregirlo
// (se puede ver con el botón del ojo) y el error se quita al volver a escribir.
function submit() {
    form.post(route('password.update'));
}

const tips = [
    { icon: ShieldCheckIcon, text: 'Que no lleve tu nombre, tu cédula ni el nombre de tu compañía.' },
    { icon: KeyRoundIcon, text: 'Que no sea la misma que usás en otros sitios.' },
    { icon: LogOutIcon, text: 'Al guardarla se cierran las sesiones que estuvieran abiertas con la anterior.' },
];
</script>

<template>
    <Head title="Nueva contraseña" />

    <AuthShell
        subtitle="Elegí tu contraseña nueva"
        lead="Es lo único que cambia: tus compañías, tus permisos y tus registros quedan como están."
    >
        <template #panel-extra>
            <ul class="tips">
                <li v-for="tip in tips" :key="tip.text">
                    <span class="tip-icon"><component :is="tip.icon" :size="17" /></span>
                    {{ tip.text }}
                </li>
            </ul>
        </template>

        <div v-if="linkIsDead" role="alert">
            <span class="auth-state-icon is-warning"><TriangleAlertIcon :size="26" /></span>

            <h2 class="auth-title">Este enlace ya no sirve</h2>
            <p class="auth-subtitle">
                Puede ser que haya vencido —dura {{ expiresInMinutes }} minutos—, que ya lo hayas usado
                o que hayas pedido otro después: solo funciona el más reciente.
            </p>

            <Link :href="route('password.request')" class="btn btn-primary auth-submit new-link">
                <MailIcon /> Pedir un enlace nuevo
            </Link>
        </div>

        <template v-else>
            <header class="auth-head">
                <h2 class="auth-title">Nueva contraseña</h2>
                <p class="auth-subtitle">Para <strong class="account">{{ email }}</strong></p>
            </header>

            <form novalidate @submit.prevent="submit">
                <!-- Para que el gestor de contraseñas del navegador sepa de
                     qué cuenta es la contraseña que va a guardar. -->
                <input type="email" name="email" :value="email" autocomplete="username" readonly hidden>

                <div class="field">
                    <label for="password">Contraseña nueva</label>
                    <div class="input-with-icon" :class="{ invalid: form.errors.password }">
                        <LockIcon class="input-icon" :size="17" />
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            autofocus
                            required
                            :aria-invalid="!!form.errors.password"
                            aria-describedby="password-rules"
                            @input="form.clearErrors('password')"
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

                <div class="field">
                    <label for="password_confirmation">Repetila</label>
                    <div class="input-with-icon">
                        <LockIcon class="input-icon" :size="17" />
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            required
                            @input="form.clearErrors('password')"
                        >
                    </div>
                </div>

                <PasswordRequirements
                    id="password-rules"
                    class="rules"
                    :requirements="passwordRequirements"
                    :password="form.password"
                    :confirmation="form.password_confirmation"
                />

                <button type="submit" class="btn btn-primary auth-submit" :disabled="form.processing">
                    <CheckIcon /> Guardar contraseña
                </button>
            </form>
        </template>

        <div class="auth-alt">
            <Link :href="route('login')" class="auth-alt-link"><ArrowLeftIcon :size="15" /> Volver a iniciar sesión</Link>
        </div>
    </AuthShell>
</template>

<style scoped>
/* ── Panel de marca ───────────────────────────────────────────────────── */

.tips {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    font-size: 0.9rem;
    line-height: 1.45;
    color: rgba(244, 246, 250, 0.8);
}

.tips li {
    display: flex;
    align-items: center;
    gap: 0.8rem;
}

.tip-icon {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.08);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.10);
    color: var(--color-accent);
}

/* ── Formulario ───────────────────────────────────────────────────────── */

.account {
    color: var(--color-text);
    overflow-wrap: anywhere;
}

.field {
    margin-bottom: 1.1rem;
}

.rules {
    margin-bottom: 1.4rem;
}

.new-link {
    margin-top: 1.5rem;
    text-decoration: none;
}
</style>
