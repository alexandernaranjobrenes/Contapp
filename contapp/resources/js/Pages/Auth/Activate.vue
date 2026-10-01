<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AuthShell from '../../Components/AuthShell.vue';
import { ArrowRightIcon, CheckIcon } from '@lucide/vue';

const form = useForm({
    code: '',
    legal_name: '',
    trade_name: '',
    tax_id: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

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
        lead="En un solo paso: el código que recibiste, los datos de tu compañía y tu usuario de Superusuario."
    >
        <template #panel-extra>
            <ol class="steps">
                <li><span class="step-number">1</span> El código de licencia que recibiste al contratar CONTAPP</li>
                <li><span class="step-number">2</span> Los datos básicos de tu compañía</li>
                <li><span class="step-number">3</span> Tu correo y una contraseña para acceder como Superusuario</li>
            </ol>
        </template>

        <header class="form-head">
            <h2 class="form-title">Activar licencia</h2>
            <p class="form-subtitle">Al terminar, entrás directo a tu compañía como Superusuario.</p>
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
                    <label for="tax_id">Cédula jurídica</label>
                    <input id="tax_id" v-model="form.tax_id" type="text">
                </div>
            </div>

            <h3 class="section-title">Tu usuario</h3>

            <div class="grid">
                <div class="field">
                    <label for="name">Nombre</label>
                    <input id="name" v-model="form.name" type="text" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>
                <div class="field">
                    <label for="email">Correo</label>
                    <input id="email" v-model="form.email" type="email" required>
                    <span v-if="form.errors.email" class="error">{{ form.errors.email }}</span>
                </div>
                <div class="field">
                    <label for="password">Contraseña</label>
                    <input id="password" v-model="form.password" type="password" required>
                    <span v-if="form.errors.password" class="error">{{ form.errors.password }}</span>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirmar contraseña</label>
                    <input id="password_confirmation" v-model="form.password_confirmation" type="password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary submit-btn" :disabled="form.processing">
                <CheckIcon /> Activar y crear compañía
            </button>
        </form>

        <div class="back-link">
            <span>¿Ya tenés cuenta?</span>
            <Link :href="route('login')" class="back-link-a">Iniciar sesión <ArrowRightIcon :size="15" /></Link>
        </div>
    </AuthShell>
</template>

<style scoped>
.form-head {
    margin-bottom: 1.25rem;
}

.form-title {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -0.01em;
}

.form-subtitle {
    margin: 0.35rem 0 0;
    font-size: 0.9rem;
    color: var(--color-text-muted);
}

.steps {
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

.steps li {
    display: flex;
    align-items: center;
    gap: 0.8rem;
}

.step-number {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    background: color-mix(in srgb, var(--color-accent) 20%, transparent);
    color: var(--color-accent);
    font-weight: 800;
    font-size: 0.85rem;
}

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

.submit-btn {
    width: 100%;
    min-height: 2.9rem;
    justify-content: center;
    margin-top: 1.25rem;
    font-size: 0.95rem;
}

.back-link {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.25rem 0.4rem;
    margin-top: 1.5rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--color-border);
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.back-link-a {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: var(--color-primary);
    font-weight: 700;
    text-decoration: none;
}

.back-link-a:hover {
    text-decoration: underline;
}
</style>
