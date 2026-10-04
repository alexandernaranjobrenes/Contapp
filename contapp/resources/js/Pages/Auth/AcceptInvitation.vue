<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';
import PasswordRequirements from '../../Components/PasswordRequirements.vue';
import { ArrowRightIcon, CheckIcon, LogOutIcon, TriangleAlertIcon } from '@lucide/vue';

/**
 * El enlace del correo de una invitación a una compañía
 * (CompanyInvitationService). Sin cuenta en CONTAPP, la persona elige acá
 * su nombre y su contraseña; con cuenta, solo acepta. Si no acepta, no
 * entra a la compañía.
 */
const props = defineProps({
    token: { type: String, required: true },
    // 'pending' | 'expired' | 'accepted' | 'invalid'
    state: { type: String, required: true },
    invitation: { type: Object, default: null },
    passwordRequirements: { type: Array, required: true },
});

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const usable = computed(() => props.state === 'pending' && props.invitation);
const newAccount = computed(() => !!props.invitation && !props.invitation.has_account);

// Hasta cuándo sirve el enlace, en la fecha de quien lo abre.
const linkExpiry = computed(() => {
    if (!props.invitation?.expires_at) return null;

    return new Intl.DateTimeFormat('es-CR', { day: 'numeric', month: 'long' }).format(new Date(props.invitation.expires_at));
});

function submit() {
    form.post(route('company-invitation.accept', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

// Con la sesión de otra cuenta abierta: cerrarla y volver a este enlace.
function logoutAndReturn() {
    router.delete(route('logout'), {
        onFinish: () => router.visit(route('company-invitation.show', props.token)),
    });
}
</script>

<template>
    <Head title="Aceptar invitación" />

    <AuthShell
        wide
        subtitle="Te invitaron a una compañía"
        lead="Alguien te invitó a trabajar en su compañía en CONTAPP. Entrás cuando aceptás la invitación."
    >
        <template #panel-extra>
            <ol class="auth-steps">
                <li v-if="newAccount"><span class="auth-step-number">1</span> Tu nombre y tu contraseña, que solo vas a saber vos</li>
                <li><span class="auth-step-number">{{ newAccount ? 2 : 1 }}</span> Aceptás la invitación</li>
                <li><span class="auth-step-number">{{ newAccount ? 3 : 2 }}</span> Entrás directo a la compañía</li>
            </ol>
        </template>

        <!-- El enlace no sirve: vencido, ya usado o desconocido. -->
        <div v-if="!usable" role="alert">
            <span class="auth-state-icon is-warning"><TriangleAlertIcon :size="26" /></span>
            <h2 class="auth-title">
                <template v-if="state === 'expired'">Esta invitación venció</template>
                <template v-else-if="state === 'accepted'">Esta invitación ya se aceptó</template>
                <template v-else>Este enlace ya no sirve</template>
            </h2>
            <p class="auth-subtitle">
                <template v-if="state === 'expired'">
                    Pedile a quien te invitó que te la vuelva a enviar, y aceptala desde el enlace nuevo.
                </template>
                <template v-else-if="state === 'accepted'">
                    Ya tenés acceso a la compañía: iniciá sesión con tu cuenta.
                </template>
                <template v-else>
                    Puede que te hayan enviado un enlace más nuevo —solo funciona el último— o que la invitación se haya
                    cancelado. Si ya la aceptaste, iniciá sesión.
                </template>
            </p>
            <Link :href="route('login')" class="btn btn-primary auth-submit next-link">Iniciar sesión <ArrowRightIcon /></Link>
        </div>

        <template v-else>
            <header class="auth-head">
                <h2 class="auth-title">{{ invitation.company }}</h2>
                <p class="auth-subtitle">
                    <template v-if="invitation.inviter">{{ invitation.inviter }} te invitó</template>
                    <template v-else>Te invitaron</template>
                    como <strong>{{ invitation.role }}</strong>.
                    <template v-if="linkExpiry">La invitación sirve hasta el {{ linkExpiry }}.</template>
                </p>
            </header>

            <p v-if="form.errors.invitation" class="flash flash-error auth-error" role="alert">{{ form.errors.invitation }}</p>

            <!-- Con la sesión de otra cuenta no se puede aceptar. -->
            <div v-if="invitation.session_mismatch" class="mismatch" role="alert">
                <p>
                    La invitación es para <strong>{{ invitation.email }}</strong>, y tenés abierta la sesión de
                    <strong>{{ invitation.session_email }}</strong>. Cerrala para aceptarla con la cuenta correcta.
                </p>
                <button type="button" class="btn btn-primary auth-submit" @click="logoutAndReturn">
                    <LogOutIcon /> Cerrar sesión y continuar
                </button>
            </div>

            <form v-else @submit.prevent="submit">
                <h3 class="section-title">Tu cuenta</h3>
                <p class="account-line">
                    <strong v-if="invitation.account_name">{{ invitation.account_name }} · </strong>{{ invitation.email }}
                </p>
                <p class="account-note">
                    <template v-if="newAccount">
                        Todavía no tenés cuenta en CONTAPP: se crea al aceptar. Elegí cómo querés que se vea tu nombre y
                        la contraseña con la que vas a entrar.
                    </template>
                    <template v-else>
                        Es tu cuenta de CONTAPP: la compañía se suma a las que ya ves y entrás con tu contraseña de
                        siempre. Lo que tenés en otras compañías sigue igual.
                    </template>
                </p>

                <template v-if="newAccount">
                    <!-- Para que el gestor de contraseñas sepa de qué cuenta es. -->
                    <input type="email" name="email" :value="invitation.email" autocomplete="username" readonly hidden>

                    <div class="field">
                        <label for="name">Nombre y apellidos</label>
                        <input id="name" v-model="form.name" type="text" autocomplete="name" required @input="form.clearErrors('name')">
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>

                    <div class="grid">
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

                <button type="submit" class="btn btn-primary auth-submit submit-btn" :disabled="form.processing">
                    <CheckIcon /> {{ newAccount ? 'Crear mi cuenta y aceptar' : 'Aceptar invitación' }}
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

.mismatch p {
    margin: 0 0 1rem;
    font-size: 0.88rem;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.submit-btn {
    margin-top: 1.25rem;
}

.next-link {
    margin-top: 1.5rem;
    text-decoration: none;
}
</style>
