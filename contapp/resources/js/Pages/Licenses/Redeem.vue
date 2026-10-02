<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { KeyRoundIcon, MailIcon } from '@lucide/vue';

const props = defineProps({
    account: { type: Object, required: true },
    // La licencia de la que esta cuenta ya es dueña; una cuenta tiene una sola.
    ownedLicense: { type: Object, default: null },
    // Falso si la contraseña vigente la definió quien creó la cuenta.
    passwordChosen: { type: Boolean, required: true },
});

const form = useForm({
    code: '',
    legal_name: '',
    trade_name: '',
    tax_id: '',
});

function submit() {
    form.post(route('license-redemption.store'));
}

function sendPasswordLink() {
    router.post(route('license-redemption.password-link'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Activar una licencia" />

    <AppLayout title="Activar una licencia">
        <div class="card redeem-card">
            <p v-if="ownedLicense" class="muted state">
                Tu cuenta ya es dueña de la licencia {{ ownedLicense.masked_code }}, y cada cuenta puede tener una sola.
                Para sumar compañías, entrá a una de las tuyas y usá Administración → Agregar compañía.
            </p>

            <!-- La contraseña la puso quien creó la cuenta, así que esa
                 persona la conoce: antes de ser dueña de una licencia, la
                 cuenta tiene que tener una contraseña que solo sepa su dueño. -->
            <div v-else-if="!passwordChosen" class="state">
                <p class="flash flash-warning notice">
                    Antes de activar una licencia, confirmá que esta cuenta es tuya. La creó otra persona, que le puso la
                    contraseña inicial, y con una licencia a tu nombre solo vos tenés que poder entrar.
                </p>
                <p class="muted">
                    Te mandamos un enlace a <strong>{{ account.email }}</strong> para que elijas tu contraseña: abrirlo
                    prueba que el correo es tuyo. Al guardarla se cierra esta sesión; entrás con la nueva y volvés a esta
                    pantalla.
                </p>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" @click="sendPasswordLink">
                        <MailIcon /> Enviarme el enlace
                    </button>
                </div>
            </div>

            <form v-else @submit.prevent="submit">
                <p class="muted intro">
                    La licencia queda a nombre de <strong>{{ account.name }}</strong> ({{ account.email }}). Vas a ser
                    Superusuario de la compañía nueva, que pasa a ser tu compañía predeterminada. En las demás seguís con
                    el rol que ya tenés.
                </p>

                <p v-if="form.errors.account" class="flash flash-error notice" role="alert">{{ form.errors.account }}</p>

                <div class="form-grid">
                    <div class="field">
                        <label for="code">Código de licencia</label>
                        <input id="code" v-model="form.code" type="text" placeholder="CONTAPP-XXXX-XXXX-XXXX" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="legal_name">Razón social</label>
                        <input id="legal_name" v-model="form.legal_name" type="text" required>
                        <span v-if="form.errors.legal_name" class="error">{{ form.errors.legal_name }}</span>
                    </div>
                    <div class="field">
                        <label for="trade_name">Nombre comercial</label>
                        <input id="trade_name" v-model="form.trade_name" type="text">
                        <span v-if="form.errors.trade_name" class="error">{{ form.errors.trade_name }}</span>
                    </div>
                    <div class="field">
                        <label for="tax_id">Cédula jurídica</label>
                        <input id="tax_id" v-model="form.tax_id" type="text">
                        <span v-if="form.errors.tax_id" class="error">{{ form.errors.tax_id }}</span>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">
                        <KeyRoundIcon /> Activar y crear compañía
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.redeem-card { padding: 1rem 1.1rem; margin-bottom: 1rem; }
.state { margin: 0; padding: 0.5rem 0; }
.state .muted { margin: 0 0 1rem; }
.intro { margin: 0 0 1rem; font-size: 0.85rem; line-height: 1.5; }
.notice { margin: 0 0 1rem; }
.error { display: block; color: var(--color-danger); font-size: 0.75rem; margin-top: 0.2rem; }
</style>
