<script setup>
import { ref, watch } from 'vue';

/**
 * Backoffice → Licencias: a quién se asigna una licencia (al emitirla, o
 * después desde su ficha). La persona la acepta desde el correo; mientras
 * no la acepte, la licencia no se activa (LicenseInvitationService).
 *
 * Trabaja sobre el useForm de quien lo usa, con los campos assign_to
 * ('none' | 'existing' | 'new'), assign_email y assign_name. Con un correo
 * escrito para una cuenta existente, consulta al servidor qué hay detrás:
 * si la cuenta existe, si ya es dueña de una licencia y si su contraseña la
 * eligió ella (si no, al aceptar tendrá que elegir una nueva).
 */
const props = defineProps({
    form: { type: Object, required: true },
    idPrefix: { type: String, required: true },
    // Al emitir se puede no asignar («Sin asignar»: se activa con el código).
    allowNone: { type: Boolean, default: false },
});

const OPTIONS = [
    { value: 'none', label: 'Sin asignar' },
    { value: 'existing', label: 'Cuenta existente' },
    { value: 'new', label: 'Cuenta nueva' },
];

const lookup = ref(null);
const lookupLoading = ref(false);
let lookupToken = 0;

function choose(value) {
    props.form.assign_to = value;
    props.form.clearErrors('assign_to', 'assign_email', 'assign_name', 'invitation');
    lookup.value = null;
    if (value === 'existing') checkEmail();
}

async function checkEmail() {
    const email = (props.form.assign_email ?? '').trim();
    lookup.value = null;
    if (props.form.assign_to !== 'existing' || !email.includes('@')) return;

    const token = ++lookupToken;
    lookupLoading.value = true;

    try {
        const response = await fetch(`${route('backoffice.licenses.account-lookup')}?email=${encodeURIComponent(email)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok || response.redirected) throw new Error();
        const data = await response.json();
        if (token === lookupToken) lookup.value = data;
    } catch {
        // Sin respuesta, no se dice nada: al guardar, el servidor valida igual.
        if (token === lookupToken) lookup.value = null;
    } finally {
        if (token === lookupToken) lookupLoading.value = false;
    }
}

// Si cambia el correo, lo que se sabía del anterior ya no vale.
watch(() => props.form.assign_email, () => { lookup.value = null; });
</script>

<template>
    <div class="assignment">
        <div class="assign-options" role="group" :aria-label="'¿A quién se asigna?'">
            <button
                v-for="option in OPTIONS.filter((o) => allowNone || o.value !== 'none')"
                :key="option.value"
                type="button"
                class="btn"
                :class="form.assign_to === option.value ? 'btn-primary' : 'btn-ghost'"
                :aria-pressed="form.assign_to === option.value"
                @click="choose(option.value)"
            >
                {{ option.label }}
            </button>
        </div>

        <p v-if="form.assign_to === 'none'" class="assign-hint">
            Se entrega el código y el cliente la activa en la pantalla de activación.
        </p>

        <template v-else>
            <p class="assign-hint">
                <template v-if="form.assign_to === 'existing'">
                    Para alguien que ya entra a CONTAPP (por ejemplo, Administrador o Usuario en la licencia de otro).
                </template>
                <template v-else>
                    La cuenta se crea cuando la persona acepta: ahí elige su contraseña, que solo va a saber ella.
                </template>
                Le llega un correo con un enlace que dura 30 minutos, y la licencia se activa recién cuando lo acepta.
            </p>

            <div v-if="form.assign_to === 'new'" class="field">
                <label :for="`${idPrefix}-name`">Nombre y apellidos</label>
                <input :id="`${idPrefix}-name`" v-model="form.assign_name" type="text" maxlength="255" required>
                <span v-if="form.errors.assign_name" class="error">{{ form.errors.assign_name }}</span>
            </div>

            <div class="field">
                <label :for="`${idPrefix}-email`">Correo</label>
                <input
                    :id="`${idPrefix}-email`"
                    v-model="form.assign_email"
                    type="email"
                    maxlength="255"
                    required
                    @blur="checkEmail"
                >
                <span v-if="form.errors.assign_email" class="error">{{ form.errors.assign_email }}</span>

                <span v-if="form.assign_to === 'existing' && lookupLoading" class="lookup muted">Buscando la cuenta…</span>
                <template v-else-if="form.assign_to === 'existing' && lookup && !form.errors.assign_email">
                    <span v-if="!lookup.exists" class="lookup lookup-warning">No hay ninguna cuenta con ese correo. Si es una persona nueva, elegí «Cuenta nueva».</span>
                    <span v-else-if="!lookup.active" class="lookup lookup-warning">La cuenta de {{ lookup.name }} está inactiva.</span>
                    <span v-else-if="lookup.owned_license" class="lookup lookup-warning">{{ lookup.name }} ya es dueño de la licencia {{ lookup.owned_license }}: cada cuenta puede tener una sola.</span>
                    <span v-else-if="lookup.pending_license" class="lookup lookup-warning">{{ lookup.name }} ya tiene asignada la licencia {{ lookup.pending_license }}, esperando que la acepte.</span>
                    <span v-else-if="!lookup.password_chosen" class="lookup">
                        Cuenta de <strong>{{ lookup.name }}</strong>. Su contraseña la definió otra persona: al aceptar va a tener que elegir una nueva.
                    </span>
                    <span v-else class="lookup">Cuenta de <strong>{{ lookup.name }}</strong>. Acepta con su contraseña de siempre.</span>
                </template>
            </div>
        </template>

        <p v-if="form.errors.invitation" class="flash flash-error assign-error" role="alert">{{ form.errors.invitation }}</p>
    </div>
</template>

<style scoped>
.assign-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr));
    gap: 0.5rem;
    margin-bottom: 0.6rem;
}

.assign-hint {
    margin: 0 0 0.75rem;
    font-size: 0.8rem;
    line-height: 1.45;
    color: var(--color-text-muted);
}

.lookup {
    display: block;
    margin-top: 0.3rem;
    font-size: 0.78rem;
    line-height: 1.4;
    color: var(--color-text-muted);
}

.lookup-warning {
    color: var(--color-warning);
}

.assign-error {
    margin: 0.5rem 0 0;
}
</style>
