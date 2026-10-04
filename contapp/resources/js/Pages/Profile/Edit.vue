<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import PasswordRequirements from '../../Components/PasswordRequirements.vue';
import UserAvatar from '../../Components/UserAvatar.vue';
import { confirmAction } from '../../Utils/confirm';
import { CheckIcon, KeyRoundIcon, MailIcon, PencilIcon, UploadIcon, XIcon } from '@lucide/vue';

const props = defineProps({
    account: { type: Object, required: true },
    // Las compañías a las que entra esta cuenta y con qué rol. Solo se ven:
    // el rol lo da quien administra cada compañía.
    memberships: { type: Array, default: () => [] },
    passwordRequirements: { type: Array, required: true },
    emailLinkMinutes: { type: Number, required: true },
    // Qué foto se puede subir (formatos y peso), dicho por el servidor.
    photoRules: { type: Object, required: true },
});

const ROLE_LABELS = { super_admin: 'Superusuario', admin: 'Administrador', user: 'Usuario' };

const profileForm = useForm({ name: props.account.name });

const emailForm = useForm({ email: '', current_password: '' });

const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

// Los datos de una compañía propia (solo su Superusuario: ver can_edit), en
// un modal (CLAUDE.md secc. 21).
const editingCompanyId = ref(null);
const editingCompany = computed(() => props.memberships.find((m) => m.id === editingCompanyId.value) ?? null);
const companyForm = useForm({ legal_name: '', trade_name: '', tax_id: '' });

function editCompany(membership) {
    companyForm.clearErrors();
    // Cada campo se asigna al abrir: sin el rastro de otra compañía.
    companyForm.legal_name = membership.legal_name ?? '';
    companyForm.trade_name = membership.trade_name ?? '';
    companyForm.tax_id = membership.tax_id ?? '';
    editingCompanyId.value = membership.id;
}

// Bajo el nombre, la razón social (si no es el mismo nombre) y la cédula.
function membershipDetail(membership) {
    return [
        membership.trade_name ? membership.legal_name : null,
        membership.tax_id ? `Cédula jurídica ${membership.tax_id}` : null,
    ].filter(Boolean).join(' · ');
}

function closeCompany() {
    editingCompanyId.value = null;
}

function saveCompany() {
    companyForm.put(route('profile.companies.update', editingCompanyId.value), {
        preserveScroll: true,
        onSuccess: closeCompany,
    });
}

const photoForm = useForm({ photo: null });
const photoInput = ref(null);

// Elegir el archivo ya lo sube: no hay un segundo botón de «guardar».
function pickPhoto(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    // El peso se revisa antes de subir, para no esperar la subida de un
    // archivo que el servidor va a rechazar igual.
    if (file.size > props.photoRules.max_megabytes * 1024 * 1024) {
        photoForm.setError('photo', `La foto no puede pesar más de ${props.photoRules.max_megabytes} MB.`);
        event.target.value = '';
        return;
    }

    photoForm.photo = file;
    photoForm.post(route('profile.photo.update'), {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => { if (photoInput.value) photoInput.value.value = ''; },
    });
}

function removePhoto() {
    confirmAction({
        title: 'Quitar la foto de perfil',
        message: 'Tu cuenta vuelve a mostrar tus iniciales. La foto se borra y no se puede recuperar.',
        confirmLabel: 'Quitar foto',
        danger: true,
        onConfirm: () => router.delete(route('profile.photo.destroy'), {
            preserveScroll: true,
            onSuccess: () => photoForm.clearErrors(),
        }),
    });
}

function saveProfile() {
    profileForm.put(route('profile.update'), { preserveScroll: true });
}

// Las contraseñas no se quedan escritas: se vacían salga bien o salga mal.
function requestEmailChange() {
    emailForm.post(route('profile.email.request'), {
        preserveScroll: true,
        onSuccess: () => emailForm.reset(),
        onError: () => emailForm.reset('current_password'),
    });
}

function changePassword() {
    passwordForm.put(route('profile.password'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('current_password'),
    });
}
</script>

<template>
    <Head title="Mi cuenta" />

    <AppLayout title="Mi cuenta">
        <section class="card account-card" aria-labelledby="photo-title">
            <div class="card-header">
                <h2 id="photo-title" class="card-title">Foto de perfil</h2>
            </div>
            <div class="card-body photo-row">
                <UserAvatar :url="account.photo_url" :name="account.name" :size="6" />
                <div class="photo-side">
                    <p id="photo-hint" class="muted hint">
                        Se ve junto a tu nombre en la lista de usuarios de cada compañía. {{ photoRules.formats }}, hasta
                        {{ photoRules.max_megabytes }} MB; se recorta al centro en un cuadrado.
                    </p>
                    <div class="photo-actions">
                        <label class="btn btn-primary file-btn" :class="{ disabled: photoForm.processing }">
                            <UploadIcon /> {{ account.photo_url ? 'Cambiar foto' : 'Subir foto' }}
                            <input
                                ref="photoInput"
                                type="file"
                                :accept="photoRules.accept"
                                :disabled="photoForm.processing"
                                aria-describedby="photo-hint"
                                @change="pickPhoto"
                            >
                        </label>
                        <button v-if="account.photo_url" type="button" class="btn btn-ghost btn-danger-text" @click="removePhoto">
                            <XIcon /> Quitar foto
                        </button>
                    </div>
                    <span v-if="photoForm.errors.photo" class="error" role="alert">{{ photoForm.errors.photo }}</span>
                </div>
            </div>
        </section>

        <section class="card account-card" aria-labelledby="profile-title">
            <div class="card-header">
                <h2 id="profile-title" class="card-title">Datos personales</h2>
            </div>
            <form class="card-body" @submit.prevent="saveProfile">
                <div class="form-grid">
                    <div class="field">
                        <label for="profile-name">Nombre y apellidos</label>
                        <input id="profile-name" v-model="profileForm.name" type="text" autocomplete="name" maxlength="255" required>
                        <span v-if="profileForm.errors.name" class="error">{{ profileForm.errors.name }}</span>
                    </div>
                </div>
                <p class="muted hint">Es el nombre con el que te ven en todas las compañías a las que entrás.</p>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" :disabled="profileForm.processing || !profileForm.isDirty">
                        <CheckIcon /> Guardar datos
                    </button>
                </div>
            </form>
        </section>

        <section class="card account-card" aria-labelledby="email-title">
            <div class="card-header">
                <h2 id="email-title" class="card-title">Correo de acceso</h2>
                <span class="current-email">{{ account.email }}</span>
            </div>
            <form class="card-body" @submit.prevent="requestEmailChange">
                <p class="muted hint">
                    Con este correo entrás a CONTAPP y ahí te llega el enlace para recuperar la contraseña. Para cambiarlo,
                    te mandamos un enlace al correo nuevo: el cambio se hace cuando lo abrís (dura {{ emailLinkMinutes }}
                    minutos) y hasta entonces seguís entrando con el actual.
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label for="email-new">Correo nuevo</label>
                        <input id="email-new" v-model="emailForm.email" type="email" autocomplete="email" maxlength="255" required>
                        <span v-if="emailForm.errors.email" class="error">{{ emailForm.errors.email }}</span>
                    </div>
                    <div class="field">
                        <label for="email-password">Tu contraseña actual</label>
                        <input id="email-password" v-model="emailForm.current_password" type="password" autocomplete="current-password" required>
                        <span v-if="emailForm.errors.current_password" class="error">{{ emailForm.errors.current_password }}</span>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" :disabled="emailForm.processing">
                        <MailIcon /> Enviar enlace de confirmación
                    </button>
                </div>
            </form>
        </section>

        <section class="card account-card" aria-labelledby="password-title">
            <div class="card-header">
                <h2 id="password-title" class="card-title">Contraseña</h2>
            </div>
            <form class="card-body" @submit.prevent="changePassword">
                <!-- Para que el gestor de contraseñas del navegador sepa de
                     qué cuenta es la contraseña que va a guardar. -->
                <input type="email" name="email" :value="account.email" autocomplete="username" readonly hidden>

                <div class="form-grid">
                    <div class="field">
                        <label for="password-current">Contraseña actual</label>
                        <input id="password-current" v-model="passwordForm.current_password" type="password" autocomplete="current-password" required>
                        <span v-if="passwordForm.errors.current_password" class="error">{{ passwordForm.errors.current_password }}</span>
                    </div>
                    <div class="field">
                        <label for="password-new">Contraseña nueva</label>
                        <input
                            id="password-new"
                            v-model="passwordForm.password"
                            type="password"
                            autocomplete="new-password"
                            required
                            aria-describedby="password-rules"
                            @input="passwordForm.clearErrors('password')"
                        >
                        <span v-if="passwordForm.errors.password" class="error">{{ passwordForm.errors.password }}</span>
                    </div>
                    <div class="field">
                        <label for="password-confirmation">Repetila</label>
                        <input
                            id="password-confirmation"
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            @input="passwordForm.clearErrors('password')"
                        >
                    </div>
                </div>

                <PasswordRequirements
                    id="password-rules"
                    class="requirements"
                    :requirements="passwordRequirements"
                    :password="passwordForm.password"
                    :confirmation="passwordForm.password_confirmation"
                />

                <p class="muted hint">Al cambiarla se cierran tus sesiones abiertas en otros dispositivos. Esta sigue abierta.</p>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">
                        <KeyRoundIcon /> Cambiar contraseña
                    </button>
                </div>
            </form>
        </section>

        <section class="card account-card" aria-labelledby="memberships-title">
            <div class="card-header">
                <h2 id="memberships-title" class="card-title">Tus compañías</h2>
            </div>
            <div class="card-body">
                <p v-if="!memberships.length" class="muted hint">Tu cuenta todavía no pertenece a ninguna compañía.</p>
                <template v-else>
                    <p class="muted hint">
                        El rol es de cada compañía: lo asigna quien la administra, y por eso no se cambia desde acá. Los
                        datos de una compañía —razón social, nombre comercial y cédula jurídica— los cambia su Superusuario.
                    </p>
                    <ul class="memberships">
                        <li v-for="membership in memberships" :key="membership.id">
                            <span class="membership-main">
                                <span class="membership-name">{{ membership.name }}</span>
                                <span v-if="membershipDetail(membership)" class="membership-detail muted">
                                    {{ membershipDetail(membership) }}
                                </span>
                            </span>
                            <span class="membership-tags">
                                <span v-if="membership.is_current" class="badge badge-success">Activa ahora</span>
                                <span v-if="membership.is_default" class="badge badge-neutral">Predeterminada</span>
                                <span class="badge badge-neutral">{{ ROLE_LABELS[membership.role] ?? membership.role }}</span>
                                <button
                                    v-if="membership.can_edit"
                                    type="button"
                                    class="btn btn-ghost edit-company"
                                    :aria-label="`Editar los datos de ${membership.name}`"
                                    @click="editCompany(membership)"
                                >
                                    <PencilIcon /> Editar datos
                                </button>
                            </span>
                        </li>
                    </ul>
                </template>
            </div>
        </section>

        <DetailModal :open="!!editingCompany" :title="editingCompany ? `Datos de ${editingCompany.name}` : ''" @close="closeCompany">
            <form v-if="editingCompany" id="company-details-form" @submit.prevent="saveCompany">
                <p class="muted hint">
                    La razón social y la cédula jurídica salen en el encabezado de los reportes y en los comprobantes
                    electrónicos que se emitan desde ahora. Los que ya se emitieron no cambian.
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label for="company-legal-name">Razón social</label>
                        <input id="company-legal-name" v-model="companyForm.legal_name" type="text" maxlength="255" required>
                        <span v-if="companyForm.errors.legal_name" class="error">{{ companyForm.errors.legal_name }}</span>
                    </div>
                    <div class="field">
                        <label for="company-trade-name">Nombre comercial</label>
                        <input id="company-trade-name" v-model="companyForm.trade_name" type="text" maxlength="255" aria-describedby="company-trade-name-hint">
                        <span id="company-trade-name-hint" class="field-hint muted">Si lo dejás vacío, se usa la razón social.</span>
                        <span v-if="companyForm.errors.trade_name" class="error">{{ companyForm.errors.trade_name }}</span>
                    </div>
                    <div class="field">
                        <label for="company-tax-id">Cédula jurídica (opcional)</label>
                        <input id="company-tax-id" v-model="companyForm.tax_id" type="text" maxlength="50">
                        <span v-if="companyForm.errors.tax_id" class="error">{{ companyForm.errors.tax_id }}</span>
                    </div>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="closeCompany">Cancelar</button>
                <button type="submit" form="company-details-form" class="btn btn-primary" :disabled="companyForm.processing">
                    <CheckIcon /> Guardar datos
                </button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.account-card { margin-bottom: 1rem; }

/* La foto a la izquierda y lo demás al lado; en un teléfono, uno debajo del otro. */
.photo-row { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem 1.25rem; }
.photo-side { flex: 1 1 16rem; min-width: 0; }
.photo-side .hint { margin-bottom: 0.75rem; }
.photo-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.photo-actions + .error { margin-top: 0.5rem; }
.card-title { margin: 0; font-size: 0.95rem; font-weight: 700; }
.card-body { padding: 1rem 1.1rem; }
.current-email { font-size: 0.85rem; color: var(--color-text-muted); overflow-wrap: anywhere; }
.hint { margin: 0 0 1rem; font-size: 0.85rem; line-height: 1.5; }
.form-grid + .hint { margin-top: 0.25rem; }
.requirements { margin: 0.25rem 0 1rem; }
.error { display: block; color: var(--color-danger); font-size: 0.75rem; margin-top: 0.2rem; }

.memberships {
    list-style: none;
    margin: 0;
    padding: 0;
}

.memberships li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.4rem 1rem;
    padding: 0.6rem 0;
    border-top: 1px solid var(--color-border);
    font-size: 0.88rem;
}

.membership-main { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
.membership-name { font-weight: 600; overflow-wrap: anywhere; }
.membership-detail { font-size: 0.8rem; overflow-wrap: anywhere; }
.edit-company { padding: 0.2rem 0.55rem; font-size: 0.78rem; }
.field-hint { display: block; margin-top: 0.2rem; font-size: 0.75rem; }
.membership-tags { display: inline-flex; flex-wrap: wrap; gap: 0.35rem; }
</style>
