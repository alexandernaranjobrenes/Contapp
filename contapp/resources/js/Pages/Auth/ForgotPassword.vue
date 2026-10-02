<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';
import { ArrowLeftIcon, ClockIcon, InboxIcon, MailCheckIcon, MailIcon, SendIcon } from '@lucide/vue';

const props = defineProps({
    // Cuánto dura el enlace y cada cuánto se puede pedir otro: los dos salen
    // de config/auth.php, para que la pantalla no prometa algo distinto.
    expiresInMinutes: { type: Number, required: true },
    resendAfterSeconds: { type: Number, required: true },
});

const form = useForm({ email: '' });

// El correo al que se pidió el enlace; null mientras se muestra el formulario.
const sentTo = ref(null);
const sentTitle = ref(null);
const emailInput = ref(null);

// El servidor no manda otro enlace al mismo correo antes de resendAfterSeconds,
// y responde igual lo mande o no. La cuenta regresiva evita que «Reenviar» se
// presione cuando no va a salir nada.
const secondsLeft = ref(0);
let timer = null;

function startCountdown() {
    clearInterval(timer);
    secondsLeft.value = props.resendAfterSeconds;
    timer = setInterval(() => {
        secondsLeft.value -= 1;
        if (secondsLeft.value <= 0) clearInterval(timer);
    }, 1000);
}

// Al llegar desde el login no hay carga de página, así que el navegador no
// aplica el atributo autofocus: el foco se pone a mano.
onMounted(() => emailInput.value?.focus());
onBeforeUnmount(() => clearInterval(timer));

function submit() {
    form.post(route('password.email'), {
        preserveState: true,
        onSuccess: () => {
            const firstTime = sentTo.value === null;
            sentTo.value = form.email;
            startCountdown();
            // El foco pasa al aviso, para que un lector de pantalla lo diga.
            if (firstTime) nextTick(() => sentTitle.value?.focus());
        },
    });
}

function useAnotherEmail() {
    clearInterval(timer);
    sentTo.value = null;
    // No form.reset(): después de un envío exitoso, Inertia toma lo enviado
    // como valor inicial, y el campo volvería con el correo anterior.
    form.email = '';
    form.clearErrors();
    nextTick(() => emailInput.value?.focus());
}
</script>

<template>
    <Head title="Recuperar contraseña" />

    <AuthShell
        subtitle="Recuperá el acceso a tu cuenta"
        lead="Te mandamos un enlace a tu correo para que elijas una contraseña nueva. Tus compañías y tus datos quedan como están."
    >
        <template #panel-extra>
            <ol class="auth-steps">
                <li><span class="auth-step-number">1</span> Escribí el correo con el que ingresás a CONTAPP</li>
                <li><span class="auth-step-number">2</span> Abrí el enlace que te llega: dura {{ expiresInMinutes }} minutos</li>
                <li><span class="auth-step-number">3</span> Elegí tu contraseña nueva e ingresá con ella</li>
            </ol>
        </template>

        <template v-if="sentTo === null">
            <header class="auth-head">
                <h2 class="auth-title">¿Olvidaste tu contraseña?</h2>
                <p class="auth-subtitle">Escribí tu correo y te enviamos un enlace para elegir una nueva.</p>
            </header>

            <form novalidate @submit.prevent="submit">
                <div class="field">
                    <label for="email">Correo</label>
                    <div class="input-with-icon" :class="{ invalid: form.errors.email }">
                        <MailIcon class="input-icon" :size="17" />
                        <input
                            id="email"
                            ref="emailInput"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            placeholder="nombre@empresa.com"
                            required
                            :aria-invalid="!!form.errors.email"
                            :aria-describedby="form.errors.email ? 'email-error' : null"
                        >
                    </div>
                    <span v-if="form.errors.email" id="email-error" class="error">{{ form.errors.email }}</span>
                </div>

                <button type="submit" class="btn btn-primary auth-submit" :disabled="form.processing">
                    <SendIcon /> Enviar enlace
                </button>
            </form>
        </template>

        <div v-else class="sent">
            <span class="auth-state-icon"><MailCheckIcon :size="26" /></span>

            <h2 ref="sentTitle" class="auth-title" tabindex="-1">Revisá tu correo</h2>
            <p class="auth-subtitle" role="status">
                Si <strong class="sent-email">{{ sentTo }}</strong> tiene una cuenta en CONTAPP,
                en un momento te llega un enlace para elegir una contraseña nueva.
            </p>

            <ul class="sent-notes">
                <li><ClockIcon :size="17" /> El enlace dura {{ expiresInMinutes }} minutos y sirve una sola vez.</li>
                <li><InboxIcon :size="17" /> Si no lo ves, revisá la carpeta de correo no deseado.</li>
            </ul>

            <p v-if="form.errors.email" class="flash flash-error auth-error" role="alert">{{ form.errors.email }}</p>

            <button
                type="button"
                class="btn btn-ghost auth-submit"
                :disabled="secondsLeft > 0 || form.processing"
                @click="submit"
            >
                <SendIcon />
                <template v-if="secondsLeft > 0">Podés reenviarlo en {{ secondsLeft }} s</template>
                <template v-else>Reenviar enlace</template>
            </button>

            <p class="other-email">
                ¿Te equivocaste de correo?
                <button type="button" class="link-button" @click="useAnotherEmail">Usar otro</button>
            </p>
        </div>

        <div class="auth-alt">
            <Link :href="route('login')" class="auth-alt-link"><ArrowLeftIcon :size="15" /> Volver a iniciar sesión</Link>
        </div>
    </AuthShell>
</template>

<style scoped>
.field {
    margin-bottom: 1.25rem;
}

.sent-email {
    color: var(--color-text);
    overflow-wrap: anywhere;
}

.sent-notes {
    list-style: none;
    padding: 0;
    margin: 1.25rem 0 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    font-size: 0.85rem;
    line-height: 1.45;
    color: var(--color-text-muted);
}

.sent-notes li {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
}

.sent-notes svg {
    flex-shrink: 0;
    margin-top: 0.1rem;
}

.other-email {
    margin: 0.9rem 0 0;
    text-align: center;
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

/* Un botón, porque no navega: vuelve al formulario en la misma pantalla. Con
   aspecto de enlace, igual que el del pie. */
.link-button {
    padding: 0;
    border: 0;
    background: none;
    font: inherit;
    font-weight: 700;
    color: var(--auth-link);
    cursor: pointer;
}

.link-button:hover {
    text-decoration: underline;
}
</style>
