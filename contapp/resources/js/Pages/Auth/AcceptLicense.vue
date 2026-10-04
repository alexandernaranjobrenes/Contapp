<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';
import PasswordRequirements from '../../Components/PasswordRequirements.vue';
import { ArrowRightIcon, CheckIcon, TriangleAlertIcon } from '@lucide/vue';

/**
 * El enlace del correo de una licencia asignada desde el backoffice
 * (LicenseInvitationService): la persona la acepta con los datos de su
 * primera compañía y, si hace falta, eligiendo su contraseña. Si no la
 * acepta, la licencia no se activa.
 */
const props = defineProps({
    token: { type: String, required: true },
    // 'pending' | 'expired' | 'accepted' | 'invalid'
    state: { type: String, required: true },
    invitation: { type: Object, default: null },
    passwordRequirements: { type: Array, required: true },
});

const form = useForm({
    legal_name: '',
    trade_name: '',
    tax_id: '',
    password: '',
    password_confirmation: '',
});

const usable = computed(() => props.state === 'pending' && props.invitation);

// Hasta cuándo sirve el enlace, en la hora de quien lo abre.
const linkExpiry = computed(() => {
    if (!props.invitation?.expires_at) return null;

    return new Intl.DateTimeFormat('es-CR', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .format(new Date(props.invitation.expires_at));
});

function submit() {
    form.post(route('license-invitation.accept', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Aceptar licencia" />

    <AuthShell
        wide
        subtitle="Aceptá tu licencia de CONTAPP"
        lead="El equipo de CONTAPP te asignó una licencia. Se activa cuando la aceptás: con los datos de tu primera compañía, y tu contraseña si hace falta."
    >
        <template #panel-extra>
            <ol class="auth-steps">
                <li><span class="auth-step-number">1</span> Los datos básicos de tu primera compañía</li>
                <li v-if="!invitation || invitation.requires_password"><span class="auth-step-number">2</span> Tu contraseña, que solo vas a saber vos</li>
                <li><span class="auth-step-number">{{ !invitation || invitation.requires_password ? 3 : 2 }}</span> Entrás directo a tu compañía como Superusuario</li>
            </ol>
        </template>

        <!-- El enlace no sirve: vencido, ya usado o desconocido. -->
        <div v-if="!usable" role="alert">
            <span class="auth-state-icon is-warning"><TriangleAlertIcon :size="26" /></span>
            <h2 class="auth-title">
                <template v-if="state === 'expired'">Este enlace venció</template>
                <template v-else>Este enlace ya no sirve</template>
            </h2>
            <p class="auth-subtitle">
                <template v-if="state === 'expired'">
                    El enlace dura 30 minutos. La licencia no se activó: pedile al equipo de CONTAPP que te reenvíe el
                    correo, y aceptala desde el enlace nuevo.
                </template>
                <template v-else>
                    Puede que la licencia ya se haya aceptado, que te hayan enviado un enlace más nuevo —solo funciona
                    el último— o que la asignación haya cambiado. Si ya la aceptaste, iniciá sesión.
                </template>
            </p>
            <Link :href="route('login')" class="btn btn-primary auth-submit next-link">Iniciar sesión <ArrowRightIcon /></Link>
        </div>

        <template v-else>
            <header class="auth-head">
                <h2 class="auth-title">Aceptar licencia</h2>
                <p class="auth-subtitle">
                    Licencia <strong>{{ invitation.license.category ?? 'de CONTAPP' }}</strong>: hasta
                    {{ invitation.license.max_companies }} {{ invitation.license.max_companies === 1 ? 'compañía' : 'compañías' }},
                    vigente hasta el {{ invitation.license.expires_at }}.
                    <template v-if="linkExpiry">Este enlace sirve hasta las {{ linkExpiry }}.</template>
                </p>
            </header>

            <p v-if="form.errors.invitation" class="flash flash-error auth-error" role="alert">{{ form.errors.invitation }}</p>

            <form @submit.prevent="submit">
                <h3 class="section-title">Tu cuenta</h3>
                <p class="account-line">
                    <strong>{{ invitation.name }}</strong> · {{ invitation.email }}
                </p>
                <p class="account-note">
                    <template v-if="invitation.new_account">
                        Se crea al aceptar. Elegí la contraseña con la que vas a entrar.
                    </template>
                    <template v-else-if="invitation.requires_password">
                        Es tu cuenta de CONTAPP, pero su contraseña la definió otra persona. Para que solo vos puedas entrar
                        a tu licencia, elegí una nueva: desde ahora vas a entrar con ella.
                    </template>
                    <template v-else>
                        Es tu cuenta de CONTAPP: la licencia queda a su nombre y entrás con tu contraseña de siempre. Lo que
                        tenés en otras compañías sigue igual.
                    </template>
                </p>

                <template v-if="invitation.requires_password">
                    <!-- Para que el gestor de contraseñas sepa de qué cuenta es. -->
                    <input type="email" name="email" :value="invitation.email" autocomplete="username" readonly hidden>

                    <div class="grid">
                        <div class="field">
                            <label for="password">{{ invitation.new_account ? 'Contraseña' : 'Contraseña nueva' }}</label>
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
                            <label for="password_confirmation">Repetila</label>
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

                <h3 class="section-title">Tu primera compañía</h3>

                <div class="grid">
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
                        <label for="tax_id">Cédula jurídica (opcional)</label>
                        <input id="tax_id" v-model="form.tax_id" type="text">
                        <span v-if="form.errors.tax_id" class="error">{{ form.errors.tax_id }}</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary auth-submit submit-btn" :disabled="form.processing">
                    <CheckIcon /> Aceptar y activar
                </button>
            </form>
        </template>
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

.account-line {
    margin: 0 0 0.35rem;
    font-size: 0.92rem;
    overflow-wrap: anywhere;
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

.next-link {
    margin-top: 1.5rem;
    text-decoration: none;
}
</style>
