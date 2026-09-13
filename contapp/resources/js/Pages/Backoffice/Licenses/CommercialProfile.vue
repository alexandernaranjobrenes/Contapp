<script setup>
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';

const props = defineProps({
    license: { type: Object, required: true },
    profile: { type: Object, default: null },
});

const profileForm = useForm({
    contact_name: props.profile?.contact_name ?? '',
    phone: props.profile?.phone ?? '',
    commercial_email: props.profile?.commercial_email ?? '',
    referral_source: props.profile?.referral_source ?? '',
    notes: props.profile?.notes ?? '',
});

function saveProfile() {
    profileForm.post(route('backoffice.licenses.commercial-profile.store', props.license.id), { preserveScroll: true });
}

const INTERACTION_TYPES = [
    { value: 'call', label: 'Llamada' },
    { value: 'email', label: 'Correo' },
    { value: 'meeting', label: 'Reunión' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'other', label: 'Otro' },
];

const interactionForm = useForm({
    type: 'call',
    occurred_at: new Date().toISOString().slice(0, 10),
    summary: '',
});

function addInteraction() {
    interactionForm.post(route('backoffice.licenses.commercial-interactions.store', props.license.id), {
        preserveScroll: true,
        onSuccess: () => interactionForm.reset('summary'),
    });
}

const followUpForm = useForm({
    next_action_date: '',
    action_type: '',
});

function addFollowUp() {
    followUpForm.post(route('backoffice.licenses.commercial-follow-ups.store', props.license.id), {
        preserveScroll: true,
        onSuccess: () => followUpForm.reset(),
    });
}

function completeFollowUp(followUp) {
    router.post(route('backoffice.commercial-follow-ups.complete', followUp.id), {}, { preserveScroll: true });
}

function interactionLabel(type) {
    return INTERACTION_TYPES.find((t) => t.value === type)?.label ?? type;
}

const pendingFollowUps = () => (props.profile?.follow_ups ?? []).filter((f) => f.status === 'pending');
const completedFollowUps = () => (props.profile?.follow_ups ?? []).filter((f) => f.status === 'completed');
</script>

<template>
    <Head title="Perfil comercial" />

    <BackofficeLayout title="Perfil comercial">
        <template #actions>
            <Link :href="route('backoffice.licenses.index')" class="btn btn-ghost">← Volver a licencias</Link>
        </template>

        <p class="license-line">
            Licencia <strong>{{ license.masked_code }}</strong>
            <span v-if="license.category"> — {{ license.category.name }}</span>
        </p>

        <div class="columns">
            <div class="card">
                <h2>Datos de contacto</h2>
                <form class="profile-form" @submit.prevent="saveProfile">
                    <div class="field">
                        <label>Nombre de contacto</label>
                        <input v-model="profileForm.contact_name" type="text">
                    </div>
                    <div class="field">
                        <label>Teléfono</label>
                        <input v-model="profileForm.phone" type="text">
                    </div>
                    <div class="field">
                        <label>Correo comercial</label>
                        <input v-model="profileForm.commercial_email" type="email">
                    </div>
                    <div class="field">
                        <label>Origen del cliente</label>
                        <input v-model="profileForm.referral_source" type="text" placeholder="Referido, campaña...">
                    </div>
                    <div class="field">
                        <label>Notas generales</label>
                        <textarea v-model="profileForm.notes" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" :disabled="profileForm.processing">Guardar</button>
                </form>
            </div>

            <div class="card">
                <h2>Seguimientos</h2>
                <form class="inline-form" @submit.prevent="addFollowUp">
                    <input v-model="followUpForm.next_action_date" type="date" required>
                    <input v-model="followUpForm.action_type" type="text" placeholder="Ej. llamar antes de renovar" required>
                    <button type="submit" class="btn btn-primary" :disabled="followUpForm.processing">Agendar</button>
                </form>

                <h3>Pendientes</h3>
                <ul class="follow-up-list">
                    <li v-for="followUp in pendingFollowUps()" :key="followUp.id">
                        <span>{{ followUp.next_action_date }} — {{ followUp.action_type }}</span>
                        <button type="button" class="btn btn-ghost" @click="completeFollowUp(followUp)">Marcar completado</button>
                    </li>
                    <li v-if="!pendingFollowUps().length" class="muted">Sin seguimientos pendientes.</li>
                </ul>

                <h3 v-if="completedFollowUps().length">Completados</h3>
                <ul v-if="completedFollowUps().length" class="follow-up-list completed">
                    <li v-for="followUp in completedFollowUps()" :key="followUp.id">
                        {{ followUp.next_action_date }} — {{ followUp.action_type }}
                    </li>
                </ul>
            </div>
        </div>

        <div class="card">
            <h2>Interacciones</h2>
            <form class="inline-form" @submit.prevent="addInteraction">
                <select v-model="interactionForm.type">
                    <option v-for="t in INTERACTION_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <input v-model="interactionForm.occurred_at" type="date" required>
                <input v-model="interactionForm.summary" type="text" placeholder="Resumen de la interacción" required class="summary-input">
                <button type="submit" class="btn btn-primary" :disabled="interactionForm.processing">Registrar</button>
            </form>

            <p class="hint">El historial es de solo agregado: una vez registrada, una interacción no se edita ni se borra.</p>

            <ul class="interaction-list">
                <li v-for="interaction in (profile?.interactions ?? [])" :key="interaction.id">
                    <span class="badge badge-neutral">{{ interactionLabel(interaction.type) }}</span>
                    <span class="interaction-date">{{ interaction.occurred_at }}</span>
                    <span v-if="interaction.author" class="interaction-author">{{ interaction.author.name }}</span>
                    <p class="interaction-summary">{{ interaction.summary }}</p>
                </li>
                <li v-if="!(profile?.interactions ?? []).length" class="muted">Sin interacciones registradas todavía.</li>
            </ul>
        </div>
    </BackofficeLayout>
</template>

<style scoped>
.license-line { color: var(--color-text-muted); font-size: 0.85rem; margin-bottom: 1rem; }
.columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: start; margin-bottom: 1.25rem; }
.card { padding: 1rem 1.25rem; }
h2 { font-size: 0.95rem; margin: 0 0 0.75rem; }
h3 { font-size: 0.82rem; color: var(--color-text-muted); margin: 1rem 0 0.4rem; }

.profile-form .field { margin-bottom: 0.6rem; display: flex; flex-direction: column; gap: 0.25rem; }
.profile-form label { font-size: 0.78rem; color: var(--color-text-muted); }
.profile-form input, .profile-form textarea, .inline-form input, .inline-form select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.85rem;
    font-family: inherit;
}

.inline-form { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem; align-items: center; }
.summary-input { flex: 1; min-width: 220px; }

.hint { font-size: 0.78rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }

.follow-up-list, .interaction-list { list-style: none; margin: 0; padding: 0; }
.follow-up-list li { display: flex; justify-content: space-between; align-items: center; padding: 0.4rem 0; border-top: 1px solid var(--color-border); font-size: 0.85rem; }
.follow-up-list.completed li { color: var(--color-text-muted); text-decoration: line-through; }
.muted { color: var(--color-text-muted); font-size: 0.85rem; }

.interaction-list li { padding: 0.65rem 0; border-top: 1px solid var(--color-border); }
.interaction-date { color: var(--color-text-muted); font-size: 0.8rem; margin-left: 0.5rem; }
.interaction-author { color: var(--color-text-muted); font-size: 0.8rem; margin-left: 0.5rem; }
.interaction-summary { margin: 0.35rem 0 0; font-size: 0.88rem; }
.badge-neutral { background: var(--color-surface-alt); color: var(--color-text); }
</style>
