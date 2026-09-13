<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AuthShell from '../../Components/AuthShell.vue';

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

    <AuthShell wide subtitle="Activá tu licencia y creá tu primera compañía">
        <template #panel-extra>
            <ul class="checklist">
                <li>El código de licencia que recibiste al contratar CONTAPP</li>
                <li>Los datos básicos de tu compañía</li>
                <li>Tu correo y una contraseña para acceder como Superusuario</li>
            </ul>
        </template>

        <h2 class="form-title">Activar licencia</h2>

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
                Activar y crear compañía
            </button>
        </form>

        <p class="back-link">
            ¿Ya tenés cuenta? <Link :href="route('login')">Iniciar sesión</Link>
        </p>
    </AuthShell>
</template>

<style scoped>
.form-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 1.25rem;
}

.checklist {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    font-size: 0.88rem;
    opacity: .9;
}

.checklist li {
    padding-left: 1.3em;
    position: relative;
}

.checklist li::before {
    content: '•';
    position: absolute;
    left: 0.3em;
    opacity: .8;
}

.section-title {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--color-text-muted);
    margin: 1.1rem 0 0.4rem;
}

.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.submit-btn { width: 100%; justify-content: center; margin-top: 1.25rem; }

.back-link { text-align: center; font-size: 0.8rem; color: var(--color-text-muted); margin-top: 1rem; }
.back-link a { color: var(--color-primary); font-weight: 600; }
</style>
