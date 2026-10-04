<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AuthShell from '../../Components/AuthShell.vue';
import PasswordRequirements from '../../Components/PasswordRequirements.vue';
import { ArrowRightIcon, CheckIcon } from '@lucide/vue';

defineProps({
    passwordRequirements: { type: Array, required: true },
});

const form = useForm({
    // 'new': alguien nuevo, se le crea la cuenta. 'existing': alguien que ya
    // tiene cuenta en CONTAPP (Administrador o Usuario en la licencia de otra
    // persona) y activa la suya con ella: solo correo y contraseña actual.
    account: 'new',
    code: '',
    legal_name: '',
    trade_name: '',
    tax_id: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function setAccount(account) {
    if (form.account === account) return;

    form.account = account;
    // Una contraseña escrita para crear la cuenta no es la que ya se tiene.
    form.password = '';
    form.password_confirmation = '';
    form.clearErrors('name', 'email', 'password');
}

function submit() {
    form.post(route('license-activation.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Activar licencia" />

    <AuthShell
        wide
        subtitle="Activá tu licencia y creá tu primera compañía"
        lead="En un solo paso: el código que recibiste, los datos de tu compañía y tu cuenta de Superusuario."
    >
        <template #panel-extra>
            <ol class="auth-steps">
                <li><span class="auth-step-number">1</span> El código de licencia que recibiste al contratar CONTAPP</li>
                <li><span class="auth-step-number">2</span> Los datos básicos de tu compañía</li>
                <li><span class="auth-step-number">3</span> Tu cuenta: una nueva, o la que ya tenés en CONTAPP</li>
            </ol>
        </template>

        <header class="auth-head">
            <h2 class="auth-title">Activar licencia</h2>
            <p class="auth-subtitle">Al terminar, entrás directo a tu compañía como Superusuario.</p>
        </header>

        <form @submit.prevent="submit">
            <div class="field">
                <label for="code">Código de licencia</label>
                <input id="code" v-model="form.code" type="text" placeholder="CONTAPP-XXXX-XXXX-XXXX" required>
                <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
            </div>

            <h3 class="section-title">Tu compañía</h3>

            <div class="grid">
                <div class="field">
                    <label for="legal_name">Razón social</label>
                    <input id="legal_name" v-model="form.legal_name" type="text" required>
                    <span v-if="form.errors.legal_name" class="error">{{ form.errors.legal_name }}</span>
                </div>
                <div class="field">
                    <label for="trade_name">Nombre comercial</label>
                    <input id="trade_name" v-model="form.trade_name" type="text">
                </div>
                <div class="field">
                    <label for="tax_id">Cédula jurídica (opcional)</label>
                    <input id="tax_id" v-model="form.tax_id" type="text">
                </div>
            </div>

            <h3 class="section-title">Tu cuenta</h3>

            <div class="account-switch" role="group" aria-label="¿Ya tenés una cuenta en CONTAPP?">
                <button
                    type="button"
                    class="btn"
                    :class="form.account === 'new' ? 'btn-primary' : 'btn-ghost'"
                    :aria-pressed="form.account === 'new'"
                    @click="setAccount('new')"
                >
                    Soy nuevo en CONTAPP
                </button>
                <button
                    type="button"
                    class="btn"
                    :class="form.account === 'existing' ? 'btn-primary' : 'btn-ghost'"
                    :aria-pressed="form.account === 'existing'"
                    @click="setAccount('existing')"
                >
                    Ya tengo una cuenta
                </button>
            </div>

            <template v-if="form.account === 'new'">
                <div class="grid">
                    <div class="field">
                        <label for="name">Nombre</label>
                        <input id="name" v-model="form.name" type="text" autocomplete="name" required>
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>
                    <div class="field">
                        <label for="email">Correo</label>
                        <input id="email" v-model="form.email" type="email" autocomplete="username" required>
                        <span v-if="form.errors.email" class="error">{{ form.errors.email }}</span>
                    </div>
                    <div class="field">
                        <label for="password">Contraseña</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            required
                            aria-describedby="password-rules"
                            @input="form.clearErrors('password')"
                        >
                        <span v-if="form.errors.password" class="error">{{ form.errors.password }}</span>
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirmar contraseña</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            @input="form.clearErrors('password')"
                        >
                    </div>
                </div>

                <PasswordRequirements
                    id="password-rules"
                    :requirements="passwordRequirements"
                    :password="form.password"
                    :confirmation="form.password_confirmation"
                />
            </template>

            <template v-else>
                <!-- La cuenta ya existe: se comprueba con su contraseña
                     actual. Acá no se le cambia el nombre ni la contraseña. -->
                <p class="account-note">
                    Ingresá con el correo y la contraseña que ya usás. La licencia queda a nombre de esa cuenta, y lo que
                    tenés en otras compañías sigue igual.
                </p>

                <div class="grid">
                    <div class="field">
                        <label for="email">Correo</label>
                        <input id="email" v-model="form.email" type="email" autocomplete="username" required>
                        <span v-if="form.errors.email" class="error">{{ form.errors.email }}</span>
                    </div>
                    <div class="field">
                        <label for="password">Tu contraseña actual</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            @input="form.clearErrors('password')"
                        >
                        <span v-if="form.errors.password" class="error">{{ form.errors.password }}</span>
                    </div>
                </div>

                <p class="account-note">
                    <Link :href="route('password.request')" class="auth-alt-link">¿Olvidaste tu contraseña?</Link>
                </p>
            </template>

            <button type="submit" class="btn btn-primary auth-submit submit-btn" :disabled="form.processing">
                <CheckIcon /> Activar y crear compañía
            </button>
        </form>

        <div class="auth-alt">
            <span>¿Solo querés entrar?</span>
            <Link :href="route('login')" class="auth-alt-link">Iniciar sesión <ArrowRightIcon :size="15" /></Link>
        </div>
    </AuthShell>
</template>

<style scoped>
.section-title {
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--color-text-muted);
    margin: 1.25rem 0 0.5rem;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--color-border);
}

/* Dos columnas cuando caben; una en un teléfono. */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
    gap: 0 1rem;
}

/* Las dos opciones, mitad y mitad; en un teléfono, una debajo de la otra. */
.account-switch {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
    gap: 0.5rem;
    margin: 0.75rem 0 1rem;
}

.account-note {
    margin: 0 0 0.9rem;
    font-size: 0.85rem;
    line-height: 1.5;
    color: var(--color-text-muted);
}

.submit-btn {
    margin-top: 1.25rem;
}
</style>
